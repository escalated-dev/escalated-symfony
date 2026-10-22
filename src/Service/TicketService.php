<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketActivity;
use Escalated\Symfony\Entity\TicketStatus;
use Escalated\Symfony\Event\EventNames;
use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\Repository\TicketRepository;
use Escalated\Symfony\Repository\TicketStatusRepository;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class TicketService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketRepository $ticketRepository,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?\Escalated\Symfony\Security\TicketAccess $access = null,
        private readonly ?SlaService $sla = null,
        private readonly bool $targetedSlas = false,
    ) {
    }

    /**
     * Create a new ticket.
     *
     * @param array{
     *     subject: string,
     *     description?: string,
     *     priority?: string,
     *     ticket_type?: string,
     *     department_id?: int,
     *     requester_id?: int,
     *     requester_class?: string,
     *     guest_name?: string,
     *     guest_email?: string,
     *     metadata?: array,
     * } $data
     */
    public function create(array $data, ?Ticket $accountSource = null): Ticket
    {
        if ($this->access?->accountsEnabled() || $this->access?->restricted()) {
            return $this->em->wrapInTransaction(fn () => $this->createTicket($data, $accountSource));
        }

        return $this->createTicket($data, $accountSource);
    }

    private function createTicket(array $data, ?Ticket $accountSource): Ticket
    {
        $ticket = new Ticket();
        $ticket->setSubject($data['subject']);
        $ticket->setDescription($data['description'] ?? null);
        $ticket->setPriority($data['priority'] ?? Ticket::PRIORITY_MEDIUM);
        $ticket->setTicketType($data['ticket_type'] ?? null);
        $ticket->setMetadata($data['metadata'] ?? null);

        if (isset($data['requester_id'])) {
            $ticket->setRequesterId($data['requester_id']);
            $ticket->setRequesterClass($data['requester_class'] ?? null);
        }

        if (isset($data['guest_name'])) {
            $ticket->setGuestName($data['guest_name']);
            $ticket->setGuestEmail($data['guest_email'] ?? null);
            $ticket->setGuestToken(Uuid::v4()->toRfc4122());

            // Dedupe repeat guests by email (Pattern B). Inline guest_*
            // fields remain set for the backwards-compat dual-read period.
            if (!empty($data['guest_email'])) {
                $contact = $this->findOrCreateContact($data['guest_email'], $data['guest_name']);
                $ticket->setContact($contact);
            }
        }

        if (isset($data['department_id'])) {
            $department = $this->em->getReference(
                \Escalated\Symfony\Entity\Department::class,
                $data['department_id']
            );
            $ticket->setDepartment($department);
        }

        if (null !== $accountSource) {
            $this->access?->assertVisible($accountSource);
            $ticket->setAccount($accountSource->getAccountType(), $accountSource->getAccountId());
        }
        $this->access?->resolveRequester($ticket);
        $this->em->persist($ticket);
        $this->em->flush();

        // Generate the final reference from the primary key
        $ticket->setReference($ticket->generateReference());
        $this->em->flush();

        if ($this->targetedSlas) {
            $this->sla?->attachDefaultPolicy($ticket);
        }

        // Log creation activity
        $this->logActivity($ticket, TicketActivity::TYPE_CREATED, $data['requester_id'] ?? null);

        return $ticket;
    }

    /**
     * Update a ticket's mutable fields.
     */
    public function update(Ticket $ticket, array $data): Ticket
    {
        $this->access?->assertVisible($ticket);
        $oldPriority = $ticket->getPriority();

        if (isset($data['subject'])) {
            $ticket->setSubject($data['subject']);
        }
        if (isset($data['description'])) {
            $ticket->setDescription($data['description']);
        }
        if (isset($data['priority'])) {
            $ticket->setPriority($data['priority']);
        }
        if (isset($data['ticket_type'])) {
            $ticket->setTicketType($data['ticket_type']);
        }
        if (array_key_exists('metadata', $data)) {
            $ticket->setMetadata($data['metadata']);
        }

        $this->em->flush();

        $this->dispatcher->dispatch(new TicketWorkflowEvent('ticket.updated', $ticket, ['changes' => $data]));

        if (isset($data['priority']) && $data['priority'] !== $oldPriority) {
            $this->dispatcher->dispatch(new TicketWorkflowEvent(
                'ticket.priority_changed',
                $ticket,
                ['old_priority' => $oldPriority, 'new_priority' => $data['priority']],
            ));
        }

        return $ticket;
    }

    /**
     * Change the priority, recording who did it.
     */
    public function changePriority(Ticket $ticket, string $priority, int|string|null $causerId = null): Ticket
    {
        $old = $ticket->getPriority();
        if ($old === $priority) {
            return $ticket;
        }

        $this->update($ticket, ['priority' => $priority]);
        $this->logActivity($ticket, TicketActivity::TYPE_PRIORITY_CHANGED, $causerId, [
            'old_priority' => $old,
            'new_priority' => $priority,
        ]);

        return $ticket;
    }

    /**
     * Move the ticket to another department (or none), recording who did it.
     */
    public function changeDepartment(Ticket $ticket, ?int $departmentId, int|string|null $causerId = null): Ticket
    {
        $this->access?->assertVisible($ticket);

        $department = null;
        if (null !== $departmentId) {
            $department = $this->em->getRepository(\Escalated\Symfony\Entity\Department::class)->find($departmentId);
            if (null === $department) {
                throw new \InvalidArgumentException(sprintf('Department %d does not exist.', $departmentId));
            }
        }

        $oldId = $ticket->getDepartment()?->getId();
        if ($oldId === $departmentId) {
            return $ticket;
        }

        $ticket->setDepartment($department);
        $this->em->flush();

        $this->logActivity($ticket, TicketActivity::TYPE_DEPARTMENT_CHANGED, $causerId, [
            'old_department_id' => $oldId,
            'new_department_id' => $departmentId,
        ]);

        $this->dispatcher->dispatch(new TicketWorkflowEvent(
            EventNames::DEPARTMENT_CHANGED,
            $ticket,
            ['old_department_id' => $oldId, 'new_department_id' => $departmentId, 'causer_id' => $causerId],
        ));

        return $ticket;
    }

    /**
     * Transition a ticket to a new status: a built-in status, or the slug of a
     * custom status ({@see TicketStatus}). A custom status moves the built-in
     * status to its category's anchor and runs everything that anchor runs.
     * Moving between custom statuses with the same anchor skips the transition
     * check but is still saved, logged and announced; so is choosing the
     * anchor built-in itself to leave a custom status.
     */
    public function changeStatus(Ticket $ticket, string $newStatus, int|string|null $causerId = null): Ticket
    {
        $this->access?->assertVisible($ticket);

        $custom = $this->customStatus($newStatus);
        $target = $custom?->anchor() ?? $newStatus;
        $oldStatus = $ticket->getStatus();
        $oldCustom = $ticket->getCustomStatus();
        $oldCategory = $ticket->getStatusCategory();
        $sameBuiltIn = $target === $oldStatus;

        if ($sameBuiltIn ? (null === $custom && null === $oldCustom) || ($custom === $oldCustom) : !$ticket->canTransitionTo($target)) {
            throw new \InvalidArgumentException(sprintf('Cannot transition from "%s" to "%s".', $oldCustom?->getSlug() ?? $oldStatus, $newStatus));
        }

        null === $custom ? $ticket->setStatus($target) : $ticket->applyCustomStatus($custom);

        // Timestamps follow the built-in status, so only when it changes.
        if (!$sameBuiltIn) {
            $now = new \DateTimeImmutable();
            match ($target) {
                Ticket::STATUS_RESOLVED => $ticket->setResolvedAt($now),
                Ticket::STATUS_CLOSED => $ticket->setClosedAt($now),
                Ticket::STATUS_REOPENED => $ticket->setResolvedAt(null)->setClosedAt(null),
                default => null,
            };
        }

        $this->em->flush();

        $change = [
            'old_status' => $oldStatus,
            'new_status' => $target,
            'old_custom_status' => $oldCustom?->getSlug(),
            'new_custom_status' => $custom?->getSlug(),
            'old_category' => $oldCategory,
            'new_category' => $ticket->getStatusCategory(),
        ];

        $this->logActivity($ticket, TicketActivity::TYPE_STATUS_CHANGED, $causerId, $change);

        $this->dispatcher->dispatch(new TicketWorkflowEvent(
            'ticket.status_changed',
            $ticket,
            $change + ['causer_id' => $causerId],
        ));

        if (Ticket::STATUS_REOPENED === $target && !$sameBuiltIn) {
            $this->dispatcher->dispatch(new TicketWorkflowEvent(
                'ticket.reopened',
                $ticket,
                ['old_status' => $oldStatus, 'causer_id' => $causerId],
            ));
        }

        return $ticket;
    }

    /**
     * Whether a status-change input is a custom status's slug.
     */
    public function isCustomStatus(string $status): bool
    {
        return null !== $this->customStatus($status);
    }

    /**
     * The custom status a status-change input names; null for a built-in
     * status or an unknown value (rejected by the transition check).
     */
    private function customStatus(string $status): ?TicketStatus
    {
        if (\array_key_exists($status, Ticket::TRANSITIONS)) {
            return null;
        }
        $repository = $this->em->getRepository(TicketStatus::class);

        return $repository instanceof TicketStatusRepository ? $repository->findCustomBySlug($status) : null;
    }

    /**
     * Add a reply to a ticket.
     */
    public function addReply(Ticket $ticket, int|string|null $authorId, string $body, bool $isNote = false, ?string $authorClass = null): Reply
    {
        $this->access?->assertVisible($ticket);
        $reply = new Reply();
        $reply->setTicket($ticket);
        $reply->setAuthorId($authorId);
        $reply->setAuthorClass($authorClass);
        $reply->setBody($body);
        $reply->setIsInternalNote($isNote);
        $reply->setType($isNote ? 'note' : 'reply');

        $ticket->addReply($reply);
        $this->em->persist($reply);
        $this->em->flush();

        $activityType = $isNote ? TicketActivity::TYPE_NOTE_ADDED : TicketActivity::TYPE_REPLIED;
        $this->logActivity($ticket, $activityType, $authorId);

        // A public reply is `reply.created`, the workflow admin contract's name
        // (workflows saved as the earlier `ticket.replied` still match). An
        // internal note is `note.created`, so reply workflows such as
        // autoresponders never fire on private agent notes.
        $this->dispatcher->dispatch(new TicketWorkflowEvent(
            $isNote ? 'note.created' : 'reply.created',
            $ticket,
            ['reply_id' => $reply->getId(), 'author_id' => $authorId],
        ));

        return $reply;
    }

    /**
     * Add a reply to a ticket from an inbound email.
     *
     * The caller has already checked that the sender is the ticket's
     * requester. A requester user is the author ({@code $authorId} /
     * {@code $authorClass}); a guest reply has no author id and its
     * {@code authorClass} is tagged {@code "inbound_email"} so consumers
     * can tell it from agent/customer replies.
     */
    public function addInboundEmailReply(
        Ticket $ticket,
        string $body,
        int|string|null $authorId = null,
        ?string $authorClass = null,
    ): Reply {
        $this->access?->assertVisible($ticket);
        $reply = new Reply();
        $reply->setTicket($ticket);
        $reply->setAuthorId($authorId);
        $reply->setAuthorClass(null !== $authorId ? $authorClass : Reply::AUTHOR_INBOUND_EMAIL);
        $reply->setBody($body);
        $reply->setIsInternalNote(false);
        $reply->setType('reply');

        $ticket->addReply($reply);
        $this->em->persist($reply);
        $this->em->flush();

        $this->logActivity($ticket, TicketActivity::TYPE_REPLIED, $authorId);

        return $reply;
    }

    /**
     * Find a ticket by ID or reference.
     */
    public function find(int|string $idOrReference): ?Ticket
    {
        if (is_string($idOrReference)) {
            return $this->ticketRepository->findByReference($idOrReference);
        }

        return $this->ticketRepository->find($idOrReference);
    }

    /**
     * List tickets with optional filters.
     *
     * @return Ticket[]
     */
    public function list(array $filters = []): array
    {
        return $this->ticketRepository
            ->createFilteredQueryBuilder($filters)
            ->getQuery()
            ->getResult();
    }

    public function close(Ticket $ticket, int|string|null $causerId = null): Ticket
    {
        $this->access?->assertVisible($ticket);

        return $this->changeStatus($ticket, Ticket::STATUS_CLOSED, $causerId);
    }

    public function resolve(Ticket $ticket, int|string|null $causerId = null): Ticket
    {
        $this->access?->assertVisible($ticket);

        return $this->changeStatus($ticket, Ticket::STATUS_RESOLVED, $causerId);
    }

    public function reopen(Ticket $ticket, int|string|null $causerId = null): Ticket
    {
        $this->access?->assertVisible($ticket);

        return $this->changeStatus($ticket, Ticket::STATUS_REOPENED, $causerId);
    }

    /**
     * Add tags to a ticket.
     *
     * @param int[] $tagIds
     */
    public function addTags(Ticket $ticket, array $tagIds, int|string|null $causerId = null): Ticket
    {
        $this->access?->assertVisible($ticket);
        foreach ($tagIds as $tagId) {
            $tag = $this->em->getReference(\Escalated\Symfony\Entity\Tag::class, $tagId);
            $ticket->addTag($tag);

            $this->logActivity($ticket, TicketActivity::TYPE_TAG_ADDED, $causerId, [
                'tag_id' => $tagId,
            ]);
        }

        $this->em->flush();

        if (!empty($tagIds)) {
            $this->dispatcher->dispatch(new TicketWorkflowEvent(
                EventNames::TAG_ADDED,
                $ticket,
                ['tag_ids' => array_values($tagIds), 'causer_id' => $causerId, 'action' => 'added'],
            ));
        }

        return $ticket;
    }

    /**
     * Remove tags from a ticket.
     *
     * @param int[] $tagIds
     */
    public function removeTags(Ticket $ticket, array $tagIds, int|string|null $causerId = null): Ticket
    {
        $this->access?->assertVisible($ticket);
        $removed = [];
        foreach ($tagIds as $tagId) {
            $tag = $this->em->find(\Escalated\Symfony\Entity\Tag::class, $tagId);
            if (null !== $tag) {
                $ticket->removeTag($tag);
                $removed[] = $tagId;

                $this->logActivity($ticket, TicketActivity::TYPE_TAG_REMOVED, $causerId, [
                    'tag_id' => $tagId,
                ]);
            }
        }

        $this->em->flush();

        if ([] !== $removed) {
            $this->dispatcher->dispatch(new TicketWorkflowEvent(
                EventNames::TAG_REMOVED,
                $ticket,
                ['tag_ids' => $removed, 'causer_id' => $causerId, 'action' => 'removed'],
            ));
        }

        return $ticket;
    }

    private function logActivity(Ticket $ticket, string $type, int|string|null $causerId = null, array $properties = []): void
    {
        $activity = new TicketActivity();
        $activity->setTicket($ticket);
        $activity->setType($type);
        $activity->setCauserId($causerId);
        $activity->setProperties(!empty($properties) ? $properties : null);

        $ticket->addActivity($activity);
        $this->em->persist($activity);
        $this->em->flush();
    }

    /**
     * Resolve a Contact by email (case-insensitive, trimmed) or create
     * one. Matches the behavior of the Pattern B reference impl in the
     * other framework PRs.
     */
    private function findOrCreateContact(string $email, ?string $name = null): Contact
    {
        $normalized = Contact::normalizeEmail($email);
        $repo = $this->em->getRepository(Contact::class);
        $existing = $repo->findOneBy(['email' => $normalized]);

        $action = Contact::decideAction($existing, $name);

        if ('return-existing' === $action) {
            return $existing;
        }

        if ('update-name' === $action) {
            $existing->setName($name);
            $this->em->flush();

            return $existing;
        }

        // action === 'create'
        $contact = new Contact();
        $contact->setEmail($normalized);
        if (!empty($name)) {
            $contact->setName($name);
        }
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }
}
