<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketFollower;
use Escalated\Symfony\Rendering\TicketPresenter;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Rendering\UserDirectory;
use Escalated\Symfony\Repository\SavedViewRepository;
use Escalated\Symfony\Security\TicketAccess;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\AssignmentService;
use Escalated\Symfony\Service\CannedResponseService;
use Escalated\Symfony\Service\CustomFieldService;
use Escalated\Symfony\Service\MacroService;
use Escalated\Symfony\Service\SnoozeService;
use Escalated\Symfony\Service\TicketService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The agent and admin ticket desks: the queue, the ticket, and every write
 * the shared ticket pages post, as escalated-laravel's Agent and Admin
 * TicketController and BulkActionController.
 *
 * The two desks differ in their gate, their route names and a few props, so
 * the subclasses declare the routes and this class does the work.
 */
abstract class TicketDeskController extends UiController
{
    private const BULK_ACTIONS = ['status', 'priority', 'assign', 'tags', 'department', 'delete'];
    private const BULK_LIMIT = 50;
    private const LIST_FILTERS = ['status', 'priority', 'ticket_type', 'assigned_to', 'department_id', 'search'];
    private const SORTS = ['created_at' => 'createdAt', 'updated_at' => 'updatedAt', 'subject' => 'subject', 'reference' => 'reference', 'status' => 'status', 'priority' => 'priority'];

    public function __construct(
        protected readonly TicketService $tickets,
        protected readonly AssignmentService $assignments,
        protected readonly UiRendererInterface $renderer,
        protected readonly TicketPresenter $presenter,
        protected readonly UserDirectory $users,
        protected readonly UserKeyResolver $userKeys,
        protected readonly EntityManagerInterface $em,
        protected readonly MacroService $macros,
        protected readonly CannedResponseService $cannedResponses,
        protected readonly SnoozeService $snoozes,
        protected readonly SavedViewRepository $savedViews,
        #[Autowire(service: 'cache.app')]
        protected readonly CacheItemPoolInterface $cache,
        protected readonly ?TicketAccess $access = null,
        protected readonly ?CustomFieldService $customFields = null,
    ) {
    }

    /** The voter attribute that guards this desk. */
    abstract protected function gate(): string;

    /** Route name prefix, e.g. `escalated.agent.tickets`. */
    abstract protected function routes(): string;

    /**
     * @return array<string, mixed>
     */
    protected function indexProps(Request $request): array
    {
        $query = $request->query->all();
        $filters = array_intersect_key($query, array_flip(self::LIST_FILTERS));
        $filters = array_filter($filters, static fn (mixed $v): bool => \is_scalar($v) && '' !== (string) $v);
        if (isset($query['sort_by']) && \is_string($query['sort_by'])) {
            $filters['sort_by'] = self::SORTS[$query['sort_by']] ?? $query['sort_by'];
        }
        if (isset($query['sort_dir']) && \is_string($query['sort_dir'])) {
            $filters['sort_dir'] = $query['sort_dir'];
        }
        if (isset($filters['assigned_to'])) {
            $filters['assigned_to'] = UserKeyResolver::normalize($filters['assigned_to']);
        }

        $qb = $this->em->getRepository(Ticket::class)->createFilteredQueryBuilder($filters);
        if (isset($query['unassigned']) && '' !== $query['unassigned'] && '0' !== $query['unassigned']) {
            $qb->andWhere('t.assignedTo IS NULL');
        }
        if (!empty($query['following']) && null !== $me = $this->currentUserKey()) {
            $qb->andWhere('t.id IN (SELECT tf.ticketId FROM '.TicketFollower::class.' tf WHERE tf.userId = :followerId)')
                ->setParameter('followerId', $me);
        }

        return [
            'tickets' => $this->presenter->paginate(
                $qb,
                $request->query->getInt('page', 1),
                $request->query->getInt('per_page', 25),
                $this->generateUrl($this->routes().'.index'),
                fn (Ticket $t): array => $this->presenter->row($t),
                $query,
            ),
            'filters' => $query,
            'departments' => $this->presenter->departments(),
            'tags' => $this->presenter->tags(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function showProps(Ticket $ticket): array
    {
        $me = $this->currentUserKey();

        return [
            'ticket' => $this->presenter->detail($ticket),
            'departments' => $this->presenter->departments(),
            'tags' => $this->presenter->tags(),
            'cannedResponses' => $this->presenter->cannedResponses($this->cannedResponses->listForAgent($me)),
            'macros' => $this->presenter->macros($this->macros->listForAgent($me)),
            'is_following' => $this->presenter->isFollowing($ticket, $me),
            'followers_count' => $this->presenter->followersCount($ticket),
            'custom_fields' => $this->customFields?->forTicket($ticket) ?? [],
        ];
    }

    protected function doReply(string $reference, Request $request, bool $note): Response
    {
        $ticket = $this->ticketOr404($reference);
        $body = $this->payload($request)['body'] ?? null;
        if (!\is_string($body) || '' === trim($body)) {
            return $this->invalid($request, ['body' => 'The body field is required.'], ...$this->showRoute($ticket));
        }

        $user = $this->user();
        $this->tickets->addReply($ticket, $this->currentUserKey() ?? $user->getUserIdentifier(), $body, $note, $user::class);
        $this->addFlash('success', $note ? 'Internal note added.' : 'Reply sent.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    protected function doStatus(string $reference, Request $request): Response
    {
        $ticket = $this->ticketOr404($reference);
        $status = $this->payload($request)['status'] ?? null;
        if (!\is_string($status) || !\in_array($status, self::statuses(), true)) {
            return $this->invalid($request, ['status' => 'The selected status is invalid.'], ...$this->showRoute($ticket));
        }

        try {
            $this->tickets->changeStatus($ticket, $status, $this->currentUserKey());
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($request, ['status' => $e->getMessage()], ...$this->showRoute($ticket));
        }
        $this->addFlash('success', 'Ticket status updated.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    protected function doPriority(string $reference, Request $request): Response
    {
        $ticket = $this->ticketOr404($reference);
        $priority = $this->payload($request)['priority'] ?? null;
        if (!\is_string($priority) || !\in_array($priority, self::priorities(), true)) {
            return $this->invalid($request, ['priority' => 'The selected priority is invalid.'], ...$this->showRoute($ticket));
        }

        $this->tickets->changePriority($ticket, $priority, $this->currentUserKey());
        $this->addFlash('success', 'Ticket priority updated.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    protected function doAssign(string $reference, Request $request): Response
    {
        $ticket = $this->ticketOr404($reference);
        $agentId = UserKeyResolver::normalize($this->payload($request)['agent_id'] ?? null);
        if (null === $agentId) {
            return $this->invalid($request, ['agent_id' => 'The agent id field is required.'], ...$this->showRoute($ticket));
        }

        $this->assignments->assign($ticket, $agentId, $this->currentUserKey());
        $this->addFlash('success', 'Ticket assigned.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    protected function doTags(string $reference, Request $request): Response
    {
        $ticket = $this->ticketOr404($reference);
        $given = $this->payload($request)['tag_ids'] ?? null;
        if (!\is_array($given)) {
            return $this->invalid($request, ['tag_ids' => 'The tag ids field must be an array.'], ...$this->showRoute($ticket));
        }

        $wanted = $this->existingTagIds($given);
        $current = array_map(static fn (Tag $t): int => (int) $t->getId(), $ticket->getTags()->toArray());
        $toAdd = array_values(array_diff($wanted, $current));
        $toRemove = array_values(array_diff($current, $wanted));
        if ([] !== $toAdd) {
            $this->tickets->addTags($ticket, $toAdd, $this->currentUserKey());
        }
        if ([] !== $toRemove) {
            $this->tickets->removeTags($ticket, $toRemove, $this->currentUserKey());
        }
        $this->addFlash('success', 'Tags updated.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    protected function doDepartment(string $reference, Request $request): Response
    {
        $ticket = $this->ticketOr404($reference);
        $id = $this->payload($request)['department_id'] ?? null;
        if (!is_numeric($id)) {
            return $this->invalid($request, ['department_id' => 'The department id field is required.'], ...$this->showRoute($ticket));
        }

        try {
            $this->tickets->changeDepartment($ticket, (int) $id, $this->currentUserKey());
        } catch (\InvalidArgumentException) {
            return $this->invalid($request, ['department_id' => 'The selected department id is invalid.'], ...$this->showRoute($ticket));
        }
        $this->addFlash('success', 'Department updated.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    protected function doMacro(string $reference, Request $request): Response
    {
        $ticket = $this->ticketOr404($reference);
        $id = $this->payload($request)['macro_id'] ?? null;
        $me = $this->currentUserKey();
        $macro = is_numeric($id) ? $this->em->getRepository(Macro::class)->find((int) $id) : null;
        // Only a macro the agent may use: shared, or their own.
        if (null === $macro || (!$macro->isShared() && (null === $me || (string) $macro->getCreatedBy() !== (string) $me))) {
            throw $this->createNotFoundException('Macro not found.');
        }

        $this->macros->apply($macro, $ticket, $me ?? $this->user()->getUserIdentifier());
        $this->addFlash('success', sprintf('Macro "%s" applied.', $macro->getName()));

        return $this->back($request, ...$this->showRoute($ticket));
    }

    protected function doFollow(string $reference, Request $request): Response
    {
        $ticket = $this->ticketOr404($reference);
        $me = $this->currentUserKey();
        if (null === $me) {
            throw $this->createAccessDeniedException('Following needs a user with a key.');
        }

        $repository = $this->em->getRepository(TicketFollower::class);
        $existing = $repository->findOneBy(['ticketId' => $ticket->getId(), 'userId' => $me]);
        if (null !== $existing) {
            $this->em->remove($existing);
            $this->addFlash('success', 'You are no longer following this ticket.');
        } else {
            $follower = (new TicketFollower())->setTicketId((int) $ticket->getId())->setUserId($me);
            $this->em->persist($follower);
            $this->addFlash('success', 'You are now following this ticket.');
        }
        $this->em->flush();

        return $this->back($request, ...$this->showRoute($ticket));
    }

    protected function doPin(string $reference, int $replyId, Request $request): Response
    {
        $ticket = $this->ticketOr404($reference);
        $reply = $this->em->getRepository(Reply::class)->find($replyId);
        if (null === $reply || $reply->getTicket()?->getId() !== $ticket->getId() || !$reply->isInternalNote()) {
            throw $this->createNotFoundException('Note not found.');
        }

        $reply->setIsPinned(!$reply->isPinned());
        $this->em->flush();
        $this->addFlash('success', $reply->isPinned() ? 'Note pinned.' : 'Note unpinned.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    protected function doSnooze(string $reference, Request $request): Response
    {
        $ticket = $this->ticketOr404($reference);
        $until = $this->payload($request)['snoozed_until'] ?? null;
        try {
            $when = \is_string($until) && '' !== $until ? new \DateTimeImmutable($until) : null;
        } catch (\Exception) {
            $when = null;
        }
        if (null === $when || $when <= new \DateTimeImmutable()) {
            return $this->invalid($request, ['snoozed_until' => 'The snoozed until field must be a date in the future.'], ...$this->showRoute($ticket));
        }

        try {
            $this->snoozes->snooze($ticket, $when, $this->currentUserKey() ?? $this->user()->getUserIdentifier());
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($request, ['snoozed_until' => $e->getMessage()], ...$this->showRoute($ticket));
        }
        $this->addFlash('success', sprintf('Ticket snoozed until %s.', $when->format('Y-m-d H:i')));

        return $this->back($request, ...$this->showRoute($ticket));
    }

    protected function doUnsnooze(string $reference, Request $request): Response
    {
        $ticket = $this->ticketOr404($reference);
        $this->snoozes->unsnooze($ticket, $this->currentUserKey());
        $this->addFlash('success', 'Ticket unsnoozed.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    /**
     * Who else has the ticket open: each viewer is remembered for a minute.
     */
    protected function doPresence(string $reference): JsonResponse
    {
        $ticket = $this->ticketOr404($reference);
        $me = $this->currentUserKey() ?? $this->user()->getUserIdentifier();

        $item = $this->cache->getItem('escalated_presence_'.$ticket->getId());
        $viewers = $item->isHit() && \is_array($item->get()) ? $item->get() : [];
        $now = time();
        $viewers = array_filter($viewers, static fn (array $v): bool => $v['seen'] > $now - 60);
        $viewers[(string) $me] = ['id' => $me, 'name' => $this->users->name($me) ?? $this->user()->getUserIdentifier(), 'seen' => $now];
        $item->set($viewers)->expiresAfter(120);
        $this->cache->save($item);

        $others = [];
        foreach ($viewers as $key => $viewer) {
            if ((string) $key !== (string) $me) {
                $others[] = ['id' => $viewer['id'], 'name' => $viewer['name']];
            }
        }

        return new JsonResponse(['viewers' => $others]);
    }

    /**
     * One action across selected tickets, as Laravel's BulkActionController:
     * a ticket the action cannot apply to is skipped and not counted.
     */
    protected function doBulk(Request $request, bool $mayDelete): Response
    {
        $payload = $this->payload($request);
        $ids = $payload['ticket_ids'] ?? null;
        $action = $payload['action'] ?? null;
        $value = $payload['value'] ?? null;

        $errors = [];
        if (!\is_array($ids) || [] === $ids) {
            $errors['ticket_ids'] = 'The ticket ids field is required.';
        } elseif (\count($ids) > self::BULK_LIMIT) {
            $errors['ticket_ids'] = sprintf('The ticket ids field must not have more than %d items.', self::BULK_LIMIT);
        }
        if (!\is_string($action) || !\in_array($action, self::BULK_ACTIONS, true)) {
            $errors['action'] = 'The selected action is invalid.';
        } elseif ('delete' !== $action && (null === $value || '' === $value || [] === $value)) {
            $errors['value'] = 'The value field is required unless action is delete.';
        }
        if ([] !== $errors) {
            return $this->invalid($request, $errors, $this->routes().'.index');
        }
        if ('delete' === $action && !$mayDelete) {
            throw $this->createAccessDeniedException('Only admins can delete tickets.');
        }

        $me = $this->currentUserKey();
        $count = 0;
        foreach ($ids as $id) {
            $ticket = is_numeric($id) ? $this->tickets->find((int) $id) : null;
            if (null === $ticket || null !== $ticket->getDeletedAt()) {
                continue;
            }
            try {
                match ($action) {
                    'status' => $this->tickets->changeStatus($ticket, (string) $value, $me),
                    'priority' => \in_array($value, self::priorities(), true)
                        ? $this->tickets->changePriority($ticket, (string) $value, $me)
                        : throw new \InvalidArgumentException('Unknown priority.'),
                    'assign' => $this->assignments->assign($ticket, UserKeyResolver::normalize($value) ?? throw new \InvalidArgumentException('No agent.'), $me),
                    'tags' => $this->tickets->addTags($ticket, array_values(array_diff($this->existingTagIds((array) $value), array_map(static fn (Tag $t): int => (int) $t->getId(), $ticket->getTags()->toArray()))), $me),
                    'department' => is_numeric($value)
                        ? $this->tickets->changeDepartment($ticket, (int) $value, $me)
                        : throw new \InvalidArgumentException('No department.'),
                    'delete' => $this->softDelete($ticket),
                };
                ++$count;
            } catch (\InvalidArgumentException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                // As Laravel: a ticket the action cannot apply to (a status it
                // cannot move to, an agent it cannot go to) is skipped.
            }
        }

        $this->addFlash('success', sprintf('%d %s updated.', $count, 1 === $count ? 'ticket' : 'tickets'));

        return $this->back($request, $this->routes().'.index');
    }

    protected function ticketOr404(string $reference): Ticket
    {
        $ticket = $this->tickets->find($reference);
        if (null === $ticket || null !== $ticket->getDeletedAt()) {
            throw $this->createNotFoundException('Ticket not found.');
        }
        $this->access?->assertVisible($ticket);

        return $ticket;
    }

    protected function currentUserKey(): int|string|null
    {
        $user = $this->getUser();

        return $user instanceof UserInterface ? $this->userKeys->resolve($user) : null;
    }

    protected function user(): UserInterface
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    protected function showRoute(Ticket $ticket): array
    {
        return [$this->routes().'.show', ['reference' => $ticket->getReference()]];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_WAITING_ON_CUSTOMER,
            Ticket::STATUS_WAITING_ON_AGENT, Ticket::STATUS_ESCALATED, Ticket::STATUS_RESOLVED,
            Ticket::STATUS_CLOSED, Ticket::STATUS_REOPENED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function priorities(): array
    {
        return [Ticket::PRIORITY_LOW, Ticket::PRIORITY_MEDIUM, Ticket::PRIORITY_HIGH, Ticket::PRIORITY_URGENT, Ticket::PRIORITY_CRITICAL];
    }

    /**
     * @param array<mixed> $given
     *
     * @return list<int>
     */
    private function existingTagIds(array $given): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($given, 'is_numeric'))));
        if ([] === $ids) {
            return [];
        }

        $found = $this->em->getRepository(Tag::class)->findBy(['id' => $ids]);

        return array_values(array_map(static fn (Tag $t): int => (int) $t->getId(), $found));
    }

    private function softDelete(Ticket $ticket): Ticket
    {
        $ticket->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $ticket;
    }
}
