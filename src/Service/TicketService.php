<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketActivity;
use Escalated\Symfony\Repository\TicketRepository;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class TicketService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketRepository $ticketRepository,
        private readonly EventDispatcherInterface $dispatcher,
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
    public function create(array $data): Ticket
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

        $this->em->persist($ticket);
        $this->em->flush();

        // Generate the final reference from the primary key
        $ticket->setReference($ticket->generateReference());
        $this->em->flush();

        // Log creation activity
        $this->logActivity($ticket, TicketActivity::TYPE_CREATED, $data['requester_id'] ?? null);

        return $ticket;
    }

    /**
     * Update a ticket's mutable fields.
     */
    public function update(Ticket $ticket, array $data): Ticket
    {
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

        return $ticket;
    }

    /**
     * Transition a ticket to a new status.
     */
    public function changeStatus(Ticket $ticket, string $newStatus, ?int $causerId = null): Ticket
    {
        if (!$ticket->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(sprintf('Cannot transition from "%s" to "%s".', $ticket->getStatus(), $newStatus));
        }

        $oldStatus = $ticket->getStatus();
        $ticket->setStatus($newStatus);

        // Handle timestamp updates for special statuses
        $now = new \DateTimeImmutable();
        match ($newStatus) {
            Ticket::STATUS_RESOLVED => $ticket->setResolvedAt($now),
            Ticket::STATUS_CLOSED => $ticket->setClosedAt($now),
            Ticket::STATUS_REOPENED => $ticket->setResolvedAt(null)->setClosedAt(null),
            default => null,
        };

        $this->em->flush();

        $this->logActivity($ticket, TicketActivity::TYPE_STATUS_CHANGED, $causerId, [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);

        return $ticket;
    }

    /**
     * Add a reply to a ticket.
     */
    public function addReply(Ticket $ticket, int $authorId, string $body, bool $isNote = false, ?string $authorClass = null): Reply
    {
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

    public function close(Ticket $ticket, ?int $causerId = null): Ticket
    {
        return $this->changeStatus($ticket, Ticket::STATUS_CLOSED, $causerId);
    }

    public function resolve(Ticket $ticket, ?int $causerId = null): Ticket
    {
        return $this->changeStatus($ticket, Ticket::STATUS_RESOLVED, $causerId);
    }

    public function reopen(Ticket $ticket, ?int $causerId = null): Ticket
    {
        return $this->changeStatus($ticket, Ticket::STATUS_REOPENED, $causerId);
    }

    /**
     * Add tags to a ticket.
     *
     * @param int[] $tagIds
     */
    public function addTags(Ticket $ticket, array $tagIds, ?int $causerId = null): Ticket
    {
        foreach ($tagIds as $tagId) {
            $tag = $this->em->getReference(\Escalated\Symfony\Entity\Tag::class, $tagId);
            $ticket->addTag($tag);

            $this->logActivity($ticket, TicketActivity::TYPE_TAG_ADDED, $causerId, [
                'tag_id' => $tagId,
            ]);
        }

        $this->em->flush();

        return $ticket;
    }

    /**
     * Remove tags from a ticket.
     *
     * @param int[] $tagIds
     */
    public function removeTags(Ticket $ticket, array $tagIds, ?int $causerId = null): Ticket
    {
        foreach ($tagIds as $tagId) {
            $tag = $this->em->find(\Escalated\Symfony\Entity\Tag::class, $tagId);
            if (null !== $tag) {
                $ticket->removeTag($tag);

                $this->logActivity($ticket, TicketActivity::TYPE_TAG_REMOVED, $causerId, [
                    'tag_id' => $tagId,
                ]);
            }
        }

        $this->em->flush();

        return $ticket;
    }

    private function logActivity(Ticket $ticket, string $type, ?int $causerId = null, array $properties = []): void
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
