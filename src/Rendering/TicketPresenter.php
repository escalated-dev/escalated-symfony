<?php

declare(strict_types=1);

namespace Escalated\Symfony\Rendering;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Escalated\Symfony\Entity\Attachment;
use Escalated\Symfony\Entity\BusinessSchedule;
use Escalated\Symfony\Entity\CannedResponse;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\SatisfactionRating;
use Escalated\Symfony\Entity\SavedView;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketActivity;
use Escalated\Symfony\Entity\TicketFollower;
use Escalated\Symfony\Serializer\AttachmentNormalizer;
use Escalated\Symfony\Service\TicketSubjectService;

/**
 * Tickets and the records around them as the shared Inertia pages read them:
 * snake_case keys, the shapes escalated-laravel's models serialise to, and
 * only the fields a page uses.
 *
 * Every array is built field by field from an allowlist, never by
 * serialising an entity, so nothing a ticket holds for other purposes (guest
 * tokens and grant hashes, metadata, internal ids of other users) reaches a
 * page by accident. Requester-facing pages get {@see customer()}, which is
 * narrower still.
 */
class TicketPresenter
{
    public const ACTIVITY_LIMIT = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserDirectory $users,
        private readonly AttachmentNormalizer $attachments,
        private readonly ?TicketSubjectService $subjects = null,
    ) {
    }

    /**
     * A ticket as a list row: what TicketList, the dashboard and the bulk bar read.
     *
     * @return array<string, mixed>
     */
    public function row(Ticket $ticket): array
    {
        $latest = $this->replies($ticket)[0] ?? null;

        return [
            'id' => $ticket->getId(),
            'reference' => $ticket->getReference(),
            'subject' => $ticket->getSubject(),
            'status' => $ticket->getStatus(),
            'custom_status' => $ticket->getCustomStatus()?->summary(),
            'status_category' => $ticket->getStatusCategory(),
            'priority' => $ticket->getPriority(),
            'ticket_type' => $ticket->getTicketType(),
            'channel' => $ticket->getChannel(),
            'requester_name' => $this->requester($ticket)['name'],
            'requester_email' => $this->requester($ticket)['email'],
            'requester' => $this->requester($ticket),
            'assigned_to' => $ticket->getAssignedTo(),
            'assignee' => $this->users->find($ticket->getAssignedTo()),
            'department_id' => $ticket->getDepartment()?->getId(),
            'department' => $this->department($ticket->getDepartment()),
            'tags' => $this->tagList($ticket->getTags()),
            'first_response_at' => self::iso($ticket->getFirstResponseAt()),
            'first_response_due_at' => self::iso($ticket->getFirstResponseDueAt()),
            'resolution_due_at' => self::iso($ticket->getResolutionDueAt()),
            'sla_first_response_breached' => $ticket->isSlaFirstResponseBreached(),
            'sla_resolution_breached' => $ticket->isSlaResolutionBreached(),
            'snoozed_until' => self::iso($ticket->getSnoozedUntil()),
            'is_snoozed' => self::isSnoozed($ticket),
            'is_live_chat' => $ticket->isLiveChat(),
            'resolved_at' => self::iso($ticket->getResolvedAt()),
            'closed_at' => self::iso($ticket->getClosedAt()),
            'created_at' => self::iso($ticket->getCreatedAt()),
            'updated_at' => self::iso($ticket->getUpdatedAt()),
            'latest_reply' => null === $latest ? null : [
                'id' => $latest->getId(),
                'is_internal_note' => $latest->isInternalNote(),
                'author' => ['name' => $this->replyAuthorName($ticket, $latest)],
                'created_at' => self::iso($latest->getCreatedAt()),
            ],
            'last_reply_at' => self::iso($latest?->getCreatedAt()),
            'last_reply_author' => null === $latest ? null : $this->replyAuthorName($ticket, $latest),
        ];
    }

    /**
     * The staff view of one ticket: the row plus its conversation, history,
     * SLA, rating, chat and requester context.
     *
     * @return array<string, mixed>
     */
    public function detail(Ticket $ticket): array
    {
        $detail = $this->row($ticket);
        $detail['description'] = $ticket->getDescription();

        $replies = [];
        $pinned = [];
        foreach ($this->replies($ticket) as $reply) {
            $replies[] = $this->reply($ticket, $reply, true);
            if ($reply->isInternalNote() && $reply->isPinned()) {
                $pinned[] = [
                    'id' => $reply->getId(),
                    'body' => $reply->getBody(),
                    'author' => ['name' => $this->replyAuthorName($ticket, $reply)],
                    'created_at' => self::iso($reply->getCreatedAt()),
                ];
            }
        }
        $detail['replies'] = $replies;
        $detail['pinned_notes'] = $pinned;
        $detail['attachments'] = $this->ticketAttachments($ticket);

        $activities = [];
        foreach ($ticket->getActivities() as $activity) {
            if (\count($activities) >= self::ACTIVITY_LIMIT) {
                break;
            }
            $activities[] = $this->activity($activity);
        }
        $detail['activities'] = $activities;

        $policy = $ticket->getSlaPolicy();
        $detail['sla_policy'] = null === $policy ? null : ['id' => $policy->getId(), 'name' => $policy->getName()];
        $detail['satisfaction_rating'] = $this->rating($ticket);
        $detail['subjects'] = null === $this->subjects ? [] : $this->subjects->serializeForTicket($ticket);

        $chat = null === $ticket->getId() ? null : $this->em->getRepository(ChatSession::class)->findOneBy(['ticket' => $ticket->getId()]);
        $detail['chat_session_id'] = $chat?->getId();
        $detail['chat_started_at'] = self::iso($chat?->getCreatedAt());
        $detail['chat_metadata'] = $ticket->getChatMetadata();
        $detail['chat_messages'] = [];
        if ($ticket->isLiveChat() || null !== $chat) {
            foreach (array_reverse($this->replies($ticket)) as $reply) {
                if ($reply->isInternalNote()) {
                    continue;
                }
                $detail['chat_messages'][] = [
                    'id' => $reply->getId(),
                    'body' => $reply->getBody(),
                    'is_agent' => $reply->isByAgent(),
                    'author' => ['name' => $this->replyAuthorName($ticket, $reply)],
                    'created_at' => self::iso($reply->getCreatedAt()),
                ];
            }
        }

        $detail['requester_ticket_count'] = $this->requesterTicketCount($ticket);
        $detail['related_tickets'] = $this->relatedTickets($ticket);

        return $detail;
    }

    /**
     * What the requester sees of their own ticket, as escalated-laravel's
     * CustomerTicketPayload::guestWeb(): no internal notes, staff shown by
     * display name only, no assignee, metadata or guest credentials.
     *
     * @return array<string, mixed>
     */
    public function customer(Ticket $ticket, bool $guest = false): array
    {
        $replies = [];
        foreach ($this->replies($ticket) as $reply) {
            if ($reply->isInternalNote()) {
                continue;
            }
            $replies[] = $this->reply($ticket, $reply, false);
        }

        $rating = $this->rating($ticket);

        $payload = [
            'reference' => $ticket->getReference(),
            'subject' => $ticket->getSubject(),
            'description' => $ticket->getDescription(),
            'status' => $ticket->getStatus(),
            'priority' => $ticket->getPriority(),
            'channel' => $ticket->getChannel(),
            'department' => null === $ticket->getDepartment() ? null : ['name' => $ticket->getDepartment()->getName()],
            'attachments' => $this->ticketAttachments($ticket),
            'replies' => $replies,
            'satisfaction_rating' => null === $rating ? null : ['rating' => $rating['rating'], 'comment' => $rating['comment']],
            'resolved_at' => self::iso($ticket->getResolvedAt()),
            'closed_at' => self::iso($ticket->getClosedAt()),
            'created_at' => self::iso($ticket->getCreatedAt()),
            'updated_at' => self::iso($ticket->getUpdatedAt()),
        ];

        if ($guest) {
            $payload['guest_access_expires_at'] = self::iso($ticket->getGuestAccessExpiresAt());
        }

        return $payload;
    }

    /**
     * Status labels of the mobile ticket object
     * (escalated-developer-context domain-model/mobile-guest-api.md).
     */
    private const MOBILE_STATUS_LABELS = [
        Ticket::STATUS_OPEN => 'Open',
        Ticket::STATUS_IN_PROGRESS => 'In Progress',
        Ticket::STATUS_WAITING_ON_CUSTOMER => 'Waiting on Customer',
        Ticket::STATUS_WAITING_ON_AGENT => 'Waiting on Agent',
        Ticket::STATUS_ESCALATED => 'Escalated',
        Ticket::STATUS_RESOLVED => 'Resolved',
        Ticket::STATUS_CLOSED => 'Closed',
        Ticket::STATUS_REOPENED => 'Reopened',
        Ticket::STATUS_SNOOZED => 'Snoozed',
        Ticket::STATUS_LIVE => 'Live',
    ];

    /**
     * A ticket as the mobile guest API returns it: an allowlist with the
     * public replies newest first, staff by display name only, `metadata`
     * always an empty object, and the grant echoed when one is given.
     *
     * @return array<string, mixed>
     */
    public function mobile(Ticket $ticket, ?string $guestAccessToken = null, bool $withReplies = true): array
    {
        $replies = [];
        if ($withReplies) {
            foreach ($this->replies($ticket) as $reply) {
                if ($reply->isInternalNote()) {
                    continue;
                }
                $replies[] = [
                    'id' => $reply->getId(),
                    'body' => $reply->getBody(),
                    'is_internal_note' => false,
                    'is_pinned' => $reply->isPinned(),
                    'author' => $this->mobileReplyAuthor($ticket, $reply),
                    'attachments' => array_map(fn (Attachment $a): array => $this->mobileAttachment($a), $reply->getAttachments()->toArray()),
                    'created_at' => self::iso($reply->getCreatedAt()),
                ];
            }
        }

        $assignee = $ticket->getAssignedTo();
        $status = $ticket->getStatus();

        return [
            'id' => $ticket->getId(),
            'reference' => $ticket->getReference(),
            'guest_access_token' => $guestAccessToken,
            'guest_access_expires_at' => null !== $guestAccessToken ? self::iso($ticket->getGuestAccessExpiresAt()) : null,
            'subject' => $ticket->getSubject(),
            'description' => $ticket->getDescription() ?? '',
            'status' => [
                'value' => $status,
                'label' => $ticket->getCustomStatus()?->summary()['label'] ?? self::MOBILE_STATUS_LABELS[$status] ?? ucwords(str_replace('_', ' ', $status)),
            ],
            'priority' => ['value' => $ticket->getPriority(), 'label' => ucfirst($ticket->getPriority())],
            'channel' => $ticket->getChannel(),
            'metadata' => new \stdClass(),
            'requester' => $this->mobileRequester($ticket),
            'assignee' => null === $assignee ? null : ['id' => 0, 'name' => $this->users->name($assignee) ?? 'Support', 'email' => ''],
            'department' => $this->department($ticket->getDepartment()),
            'tags' => [],
            'replies' => $replies,
            'activities' => [],
            'sla' => [
                'first_response_due_at' => self::iso($ticket->getFirstResponseDueAt()),
                'first_response_at' => self::iso($ticket->getFirstResponseAt()),
                'first_response_breached' => $ticket->isSlaFirstResponseBreached(),
                'resolution_due_at' => self::iso($ticket->getResolutionDueAt()),
                'resolution_breached' => $ticket->isSlaResolutionBreached(),
            ],
            'is_following' => false,
            'followers_count' => 0,
            'resolved_at' => self::iso($ticket->getResolvedAt()),
            'closed_at' => self::iso($ticket->getClosedAt()),
            'created_at' => self::iso($ticket->getCreatedAt()),
            'updated_at' => self::iso($ticket->getUpdatedAt()),
        ];
    }

    /**
     * @return array{name: string, email: string|null}
     */
    private function mobileRequester(Ticket $ticket): array
    {
        $requester = $this->requester($ticket);

        return ['name' => $requester['name'] ?? 'Guest', 'email' => $requester['email']];
    }

    /**
     * @return array{id: int, name: string, email: string|null}
     */
    private function mobileReplyAuthor(Ticket $ticket, Reply $reply): array
    {
        if (!$reply->isByAgent()) {
            return ['id' => 0] + $this->mobileRequester($ticket);
        }

        return ['id' => 0, 'name' => $this->users->name($reply->getAuthorId()) ?? 'Support', 'email' => ''];
    }

    /**
     * @return array{id: int|null, filename: string, mime_type: string|null, size: int, url: string}
     */
    private function mobileAttachment(Attachment $attachment): array
    {
        $normalized = $this->attachment($attachment);

        return [
            'id' => $normalized['id'],
            'filename' => $normalized['original_filename'],
            'mime_type' => $normalized['mime_type'],
            'size' => $normalized['size'],
            'url' => $normalized['url'],
        ];
    }

    /**
     * A requester's ticket as a list row: the {@see customer()} allowlist
     * without the conversation.
     *
     * @return array<string, mixed>
     */
    public function customerRow(Ticket $ticket): array
    {
        $latest = null;
        foreach ($this->replies($ticket) as $reply) {
            if (!$reply->isInternalNote()) {
                $latest = $reply;
                break;
            }
        }

        return [
            'id' => $ticket->getId(),
            'reference' => $ticket->getReference(),
            'subject' => $ticket->getSubject(),
            'status' => $ticket->getStatus(),
            'priority' => $ticket->getPriority(),
            'ticket_type' => $ticket->getTicketType(),
            'channel' => $ticket->getChannel(),
            'requester' => $this->requester($ticket),
            'department' => null === $ticket->getDepartment() ? null : ['name' => $ticket->getDepartment()->getName()],
            'last_reply_at' => self::iso($latest?->getCreatedAt()),
            'created_at' => self::iso($ticket->getCreatedAt()),
            'updated_at' => self::iso($ticket->getUpdatedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reply(Ticket $ticket, Reply $reply, bool $staff): array
    {
        $author = ['name' => $this->replyAuthorName($ticket, $reply)];
        if ($staff) {
            $author['id'] = $reply->getAuthorId();
            $author['email'] = $this->replyAuthorEmail($ticket, $reply);
        }

        return [
            'id' => $reply->getId(),
            'body' => $reply->getBody(),
            'is_internal_note' => $reply->isInternalNote(),
            'is_pinned' => $staff && $reply->isPinned(),
            'type' => $reply->getType(),
            'author' => $author,
            'attachments' => array_map(fn (Attachment $a): array => $this->attachment($a), $reply->getAttachments()->toArray()),
            'created_at' => self::iso($reply->getCreatedAt()),
        ];
    }

    /**
     * @return array{id: int|null, original_filename: string, mime_type: string, size: int, url: string}
     */
    public function attachment(Attachment $attachment): array
    {
        $normalized = $this->attachments->normalize($attachment);

        return [
            'id' => $normalized['id'],
            'original_filename' => $normalized['original_filename'],
            'mime_type' => $normalized['mime_type'],
            'size' => $normalized['size'],
            'url' => $normalized['url'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activity(TicketActivity $activity): array
    {
        $causer = $this->users->find($activity->getCauserId());

        return [
            'id' => $activity->getId(),
            'type' => $activity->getType(),
            'description' => self::describe($activity),
            'properties' => $activity->getProperties() ?? [],
            'causer' => null === $causer ? null : ['id' => $causer['id'], 'name' => $causer['name']],
            'created_at' => self::iso($activity->getCreatedAt()),
            'created_at_human' => self::human($activity->getCreatedAt()),
        ];
    }

    /**
     * Laravel's length-aware paginator as Inertia receives it.
     *
     * @param callable(Ticket): array<string, mixed> $present
     * @param array<string, mixed>                   $query   the current query string, kept on page links
     *
     * @return array<string, mixed>
     */
    public function paginate(QueryBuilder $qb, int $page, int $perPage, string $path, callable $present, array $query = []): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $paginator = new Paginator($qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage), true);
        $total = \count($paginator);
        $rows = [];
        foreach ($paginator as $item) {
            $rows[] = $present($item);
        }

        $last = max(1, (int) ceil($total / $perPage));
        unset($query['page']);
        $url = static function (int $number) use ($path, $query): string {
            return $path.'?'.http_build_query([...$query, 'page' => $number]);
        };

        $links = [['url' => $page > 1 ? $url($page - 1) : null, 'label' => '&laquo; Previous', 'active' => false]];
        for ($number = 1; $number <= $last; ++$number) {
            $links[] = ['url' => $url($number), 'label' => (string) $number, 'active' => $number === $page];
        }
        $links[] = ['url' => $page < $last ? $url($page + 1) : null, 'label' => 'Next &raquo;', 'active' => false];

        $from = [] === $rows ? null : ($page - 1) * $perPage + 1;

        return [
            'current_page' => $page,
            'data' => $rows,
            'first_page_url' => $url(1),
            'from' => $from,
            'last_page' => $last,
            'last_page_url' => $url($last),
            'links' => $links,
            'next_page_url' => $page < $last ? $url($page + 1) : null,
            'path' => $path,
            'per_page' => $perPage,
            'prev_page_url' => $page > 1 ? $url($page - 1) : null,
            'to' => null === $from ? null : $from + \count($rows) - 1,
            'total' => $total,
        ];
    }

    /**
     * The SLA policy and department forms' `schedules`: every business
     * schedule as {id, name}, by name, for the shared Business hours schedule
     * picker (escalated-laravel's BusinessSchedule::orderBy('name')->get(['id', 'name'])).
     *
     * @return list<array{id: int|null, name: string}>
     */
    public function businessSchedules(): array
    {
        $schedules = $this->em->getRepository(BusinessSchedule::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC']);

        return array_map(static fn (BusinessSchedule $s): array => ['id' => $s->getId(), 'name' => $s->getName()], $schedules);
    }

    /**
     * @return list<array{id: int|null, name: string}>
     */
    public function departments(): array
    {
        $departments = $this->em->getRepository(Department::class)->findBy(['isActive' => true], ['name' => 'ASC']);

        return array_map(static fn (Department $d): array => ['id' => $d->getId(), 'name' => $d->getName()], $departments);
    }

    /**
     * @return list<array{id: int|null, name: string, color: string|null}>
     */
    public function tags(): array
    {
        return $this->tagList($this->em->getRepository(Tag::class)->findBy([], ['name' => 'ASC']));
    }

    /**
     * @param iterable<CannedResponse> $responses
     *
     * @return list<array<string, mixed>>
     */
    public function cannedResponses(iterable $responses): array
    {
        $list = [];
        foreach ($responses as $response) {
            $list[] = [
                'id' => $response->getId(),
                'title' => $response->getTitle(),
                'body' => $response->getBody(),
                'category' => $response->getCategory(),
                'is_shared' => $response->isShared(),
                'created_by' => $response->getCreatedBy(),
            ];
        }

        return $list;
    }

    /**
     * @param iterable<Macro> $macros
     *
     * @return list<array<string, mixed>>
     */
    public function macros(iterable $macros): array
    {
        $list = [];
        foreach ($macros as $macro) {
            $list[] = [
                'id' => $macro->getId(),
                'name' => $macro->getName(),
                'description' => $macro->getDescription(),
                'is_shared' => $macro->isShared(),
                'created_by' => $macro->getCreatedBy(),
            ];
        }

        return $list;
    }

    /**
     * @param iterable<SavedView> $views
     *
     * @return list<array<string, mixed>>
     */
    public function savedViews(iterable $views): array
    {
        $list = [];
        foreach ($views as $view) {
            $list[] = [
                'id' => $view->getId(),
                'name' => $view->getName(),
                'filters' => $view->getFilters(),
                'sort_by' => $view->getSortBy(),
                'sort_dir' => $view->getSortDir(),
                'is_shared' => $view->isShared(),
                'is_default' => $view->isDefault(),
                'position' => $view->getPosition(),
                'color' => $view->getColor(),
                'icon' => $view->getIcon(),
                'user_id' => $view->getUserId(),
            ];
        }

        return $list;
    }

    public function isFollowing(Ticket $ticket, int|string|null $userId): bool
    {
        if (null === $userId || null === $ticket->getId()) {
            return false;
        }

        return null !== $this->em->getRepository(TicketFollower::class)->findOneBy(['ticketId' => $ticket->getId(), 'userId' => $userId]);
    }

    public function followersCount(Ticket $ticket): int
    {
        return null === $ticket->getId() ? 0 : $this->em->getRepository(TicketFollower::class)->count(['ticketId' => $ticket->getId()]);
    }

    public static function isSnoozed(Ticket $ticket): bool
    {
        $until = $ticket->getSnoozedUntil();

        return null !== $until && $until > new \DateTimeImmutable();
    }

    /**
     * The requester as staff and the requester themselves see them.
     *
     * @return array{name: string|null, email: string|null}
     */
    public function requester(Ticket $ticket): array
    {
        $guestEmail = $ticket->getGuestEmail();
        if (null !== $guestEmail && '' !== $guestEmail) {
            return ['name' => $ticket->getGuestName() ?? 'Guest', 'email' => $guestEmail];
        }

        $user = $this->users->find($ticket->getRequesterId());
        if (null !== $user) {
            return ['name' => $user['name'], 'email' => $user['email']];
        }

        $contact = $ticket->getContact();
        if (null !== $contact) {
            return ['name' => $contact->getName() ?? $contact->getEmail(), 'email' => $contact->getEmail()];
        }

        return ['name' => $ticket->getGuestName(), 'email' => null];
    }

    /**
     * Latest first, as Laravel's `latest()`: by time, then by id, whatever
     * order the collection was loaded or added in.
     *
     * @return list<Reply>
     */
    private function replies(Ticket $ticket): array
    {
        $replies = array_values(array_filter($ticket->getReplies()->toArray(), static fn (Reply $r): bool => null === $r->getDeletedAt()));
        usort($replies, static fn (Reply $a, Reply $b): int => [$b->getCreatedAt(), $b->getId()] <=> [$a->getCreatedAt(), $a->getId()]);

        return $replies;
    }

    private function replyAuthorName(Ticket $ticket, Reply $reply): string
    {
        $meta = $reply->getMetadata();
        if (\is_array($meta) && isset($meta['author_name']) && \is_string($meta['author_name']) && '' !== $meta['author_name']) {
            return $meta['author_name'];
        }

        if (!$reply->isByAgent()) {
            return $this->requester($ticket)['name'] ?? 'Customer';
        }

        return $this->users->name($reply->getAuthorId()) ?? 'Support';
    }

    private function replyAuthorEmail(Ticket $ticket, Reply $reply): ?string
    {
        if (!$reply->isByAgent()) {
            return $this->requester($ticket)['email'];
        }

        return $this->users->find($reply->getAuthorId())['email'] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ticketAttachments(Ticket $ticket): array
    {
        $list = [];
        foreach ($ticket->getAttachments() as $attachment) {
            if (null === $attachment->getReply()) {
                $list[] = $this->attachment($attachment);
            }
        }

        return $list;
    }

    /**
     * @return array{id: int|null, rating: int, comment: string|null, created_at: string|null}|null
     */
    private function rating(Ticket $ticket): ?array
    {
        if (null === $ticket->getId()) {
            return null;
        }

        $rating = $this->em->getRepository(SatisfactionRating::class)->findOneBy(['ticket' => $ticket]);

        return null === $rating ? null : [
            'id' => $rating->getId(),
            'rating' => $rating->getRating(),
            'comment' => $rating->getComment(),
            'created_at' => self::iso($rating->getCreatedAt()),
        ];
    }

    /**
     * @param iterable<Tag> $tags
     *
     * @return list<array{id: int|null, name: string, color: string|null}>
     */
    private function tagList(iterable $tags): array
    {
        $list = [];
        foreach ($tags as $tag) {
            $list[] = ['id' => $tag->getId(), 'name' => $tag->getName(), 'color' => $tag->getColor()];
        }
        usort($list, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $list;
    }

    /**
     * @return array{id: int|null, name: string}|null
     */
    private function department(?Department $department): ?array
    {
        return null === $department ? null : ['id' => $department->getId(), 'name' => $department->getName()];
    }

    private function requesterTicketCount(Ticket $ticket): int
    {
        $qb = $this->sameRequester($ticket);
        if (null === $qb) {
            return 1;
        }

        return (int) $qb->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<array{reference: string, subject: string, status: string}>
     */
    private function relatedTickets(Ticket $ticket): array
    {
        $qb = $this->sameRequester($ticket);
        if (null === $qb) {
            return [];
        }

        $rows = $qb->select('t.reference', 't.subject', 't.status')
            ->andWhere('t.id != :current')
            ->setParameter('current', $ticket->getId())
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): array => ['reference' => $r['reference'], 'subject' => $r['subject'], 'status' => $r['status']], $rows);
    }

    private function sameRequester(Ticket $ticket): ?QueryBuilder
    {
        $qb = $this->em->getRepository(Ticket::class)->createQueryBuilder('t')->where('t.deletedAt IS NULL');

        if (null !== $ticket->getRequesterId()) {
            return $qb->andWhere('t.requesterId = :requester')->setParameter('requester', $ticket->getRequesterId());
        }

        if (null !== $ticket->getGuestEmail() && '' !== $ticket->getGuestEmail()) {
            return $qb->andWhere('t.guestEmail = :email')->setParameter('email', $ticket->getGuestEmail());
        }

        return null;
    }

    private static function describe(TicketActivity $activity): string
    {
        $properties = $activity->getProperties() ?? [];

        return match ($activity->getType()) {
            TicketActivity::TYPE_CREATED => 'created the ticket',
            TicketActivity::TYPE_STATUS_CHANGED => sprintf('changed status from %s to %s', self::label($properties['old_status'] ?? '?'), self::label($properties['new_status'] ?? '?')),
            TicketActivity::TYPE_PRIORITY_CHANGED => sprintf('changed priority from %s to %s', self::label($properties['old_priority'] ?? '?'), self::label($properties['new_priority'] ?? '?')),
            TicketActivity::TYPE_ASSIGNED => 'assigned the ticket',
            TicketActivity::TYPE_UNASSIGNED => 'unassigned the ticket',
            TicketActivity::TYPE_DEPARTMENT_CHANGED => 'changed the department',
            TicketActivity::TYPE_REPLIED => 'replied',
            TicketActivity::TYPE_NOTE_ADDED => 'added an internal note',
            TicketActivity::TYPE_TAG_ADDED => 'added a tag',
            TicketActivity::TYPE_TAG_REMOVED => 'removed a tag',
            TicketActivity::TYPE_SLA_BREACHED => 'breached the SLA',
            TicketActivity::TYPE_ESCALATED => 'escalated the ticket',
            default => self::label($activity->getType()),
        };
    }

    private static function label(mixed $value): string
    {
        return str_replace('_', ' ', (string) $value);
    }

    public static function iso(?\DateTimeInterface $at): ?string
    {
        return $at?->format(\DateTimeInterface::ATOM);
    }

    private static function human(\DateTimeInterface $at): string
    {
        $diff = time() - $at->getTimestamp();

        return match (true) {
            $diff < 60 => 'just now',
            $diff < 3600 => self::ago(intdiv($diff, 60), 'minute'),
            $diff < 86400 => self::ago(intdiv($diff, 3600), 'hour'),
            $diff < 604800 => self::ago(intdiv($diff, 86400), 'day'),
            default => $at->format('M j, Y g:i A'),
        };
    }

    private static function ago(int $count, string $unit): string
    {
        return sprintf('%d %s%s ago', $count, $unit, 1 === $count ? '' : 's');
    }
}
