<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Escalated\Symfony\Entity\SavedView;
use Escalated\Symfony\Repository\SavedViewRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * The agent panel's `inbox` and `queue` props (shared frontend
 * docs/agent-panel.md), as escalated-laravel's AgentInbox sends them.
 *
 * Every number is a count over the same filters the ticket list applies,
 * through the ticket visibility filter, so it is what the agent would see by
 * opening that list. Rail counts (`counts`, `views`) are of unresolved,
 * unsnoozed tickets; `stats` are for the current queue.
 *
 * A queue is `['key' => ..., 'title' => ..., 'filters' => [...]]`, where
 * `filters` are the queue's own (what its counts and stats are over).
 */
class AgentInbox
{
    public const SESSION_KEY = 'escalated_agent_inbox_queue';

    /** Saved views beyond this many are not listed, so the counts stay bounded. */
    public const MAX_SAVED_VIEWS = 50;

    /** Query keys that pick a page or an order, never which tickets are in a queue. */
    private const PRESENTATION_KEYS = ['page', 'per_page', 'view'];

    private const QUEUE_TITLES = ['mine' => 'My inbox', 'unassigned' => 'Unassigned', 'all' => 'All tickets'];

    private const VIEW_NAMES = [
        'urgent' => 'Urgent',
        'sla_at_risk' => 'SLA at risk',
        'waiting_on_me' => 'Waiting on me',
        'waiting_on_customer' => 'Waiting on customer',
        'recently_updated' => 'Recently updated',
    ];

    /** @var array<string, list<array{id: int|string, name: string, filters: array<string, mixed>}>> */
    private array $views = [];

    public function __construct(
        private readonly TicketListFilters $filters,
        private readonly SavedViewRepository $savedViews,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        #[Autowire('%escalated.agent_inbox.cache_seconds%')]
        private readonly int $cacheSeconds = 10,
    ) {
    }

    /**
     * Translate `assigned=me` and `assigned_to=me` into the agent's id.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function normaliseFilters(array $filters, int|string $me): array
    {
        if ('me' === ($filters['assigned'] ?? null) || 'me' === ($filters['assigned_to'] ?? null)) {
            $filters['assigned_to'] = $me;
        }
        unset($filters['assigned']);

        return $filters;
    }

    /**
     * The queue a list request shows.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{key: string, title: string, filters: array<string, mixed>}
     */
    public function queueFor(array $filters, int|string $me): array
    {
        $views = $this->views($me);

        if (isset($filters['view']) && '' !== $filters['view']) {
            foreach ($views as $view) {
                if ((string) $view['id'] === (string) $filters['view']) {
                    return $this->viewQueue($view);
                }
            }
        }

        $selection = $this->comparable($filters);
        if ([] !== $selection) {
            foreach ($views as $view) {
                if ([] !== $view['filters'] && $this->comparable($view['filters']) === $selection) {
                    return $this->viewQueue($view);
                }
            }
        }

        if (isset($filters['assigned_to']) && (string) $filters['assigned_to'] === (string) $me) {
            return $this->workspaceQueue('mine', $me);
        }
        if (TicketListFilters::flag($filters, 'unassigned')) {
            return $this->workspaceQueue('unassigned', $me);
        }

        return $this->workspaceQueue('all', $me);
    }

    /**
     * The filters the list applies: the request's, over a view picked by id.
     *
     * @param array<string, mixed>                              $filters
     * @param array{key: string, filters: array<string, mixed>} $queue
     *
     * @return array<string, mixed>
     */
    public function listFilters(array $filters, array $queue): array
    {
        if (isset($filters['view']) && str_starts_with($queue['key'], 'view:')) {
            unset($filters['view']);

            return array_merge($queue['filters'], $filters);
        }

        return $filters;
    }

    /** Remember the queue for the ticket page, which is opened from the list. */
    public function remember(Request $request, array $queue): void
    {
        if ($request->hasSession()) {
            $request->getSession()->set(self::SESSION_KEY, $queue['key']);
        }
    }

    /**
     * The queue last shown in the list, or All tickets.
     *
     * @return array{key: string, title: string, filters: array<string, mixed>}
     */
    public function recalled(Request $request, int|string $me): array
    {
        $key = $request->hasSession() ? $request->getSession()->get(self::SESSION_KEY) : null;
        if (!\is_string($key)) {
            return $this->workspaceQueue('all', $me);
        }
        if (str_starts_with($key, 'view:')) {
            // The view may have been deleted or unshared since.
            foreach ($this->views($me) as $view) {
                if ((string) $view['id'] === substr($key, 5)) {
                    return $this->viewQueue($view);
                }
            }

            return $this->workspaceQueue('all', $me);
        }

        return $this->workspaceQueue($key, $me);
    }

    /** @return array{key: string, title: string} */
    public function queueProp(array $queue): array
    {
        return ['key' => $queue['key'], 'title' => $queue['title']];
    }

    /**
     * The `inbox` prop for this agent and queue, cached per agent and queue.
     *
     * @return array{counts: array<string, int>, views: list<array<string, mixed>>, stats: array<string, int>}
     */
    public function summary(int|string $me, array $queue): array
    {
        if ($this->cacheSeconds <= 0) {
            return $this->build($me, $queue);
        }

        $item = $this->cache->getItem(sprintf(
            'escalated_agent_inbox_%s_%s',
            sha1((string) $me),
            sha1(json_encode([$queue['key'], $this->comparable($queue['filters'])], \JSON_THROW_ON_ERROR)),
        ));
        if ($item->isHit()) {
            return $item->get();
        }

        $value = $this->build($me, $queue);
        $this->cache->save($item->set($value)->expiresAfter($this->cacheSeconds));

        return $value;
    }

    /**
     * Built-in views, then the agent's own and shared saved views.
     *
     * @return list<array{id: int|string, name: string, filters: array<string, mixed>}>
     */
    public function views(int|string $me): array
    {
        $key = (string) $me;
        if (isset($this->views[$key])) {
            return $this->views[$key];
        }

        $views = [];
        foreach ([
            'urgent' => ['priority' => 'urgent', 'open' => 1],
            'sla_at_risk' => ['sla_at_risk' => 1],
            'waiting_on_me' => ['assigned_to' => $me, 'status' => 'waiting_on_agent'],
            'waiting_on_customer' => ['assigned_to' => $me, 'status' => 'waiting_on_customer'],
            'recently_updated' => ['open' => 1, 'updated_within_hours' => 24, 'sort_by' => 'updated_at'],
        ] as $id => $filters) {
            $views[] = ['id' => $id, 'name' => self::VIEW_NAMES[$id], 'filters' => $filters];
        }

        $saved = $this->savedViews->createQueryBuilder('v')
            ->where('v.userId = :userId OR v.isShared = true')
            ->setParameter('userId', $me)
            ->orderBy('v.position', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->setMaxResults(self::MAX_SAVED_VIEWS)
            ->getQuery()
            ->getResult();

        foreach ($saved as $view) {
            \assert($view instanceof SavedView);
            $views[] = [
                'id' => (int) $view->getId(),
                'name' => $view->getName(),
                'filters' => $this->normaliseFilters($view->getFilters(), $me),
            ];
        }

        return $this->views[$key] = $views;
    }

    private function build(int|string $me, array $queue): array
    {
        $base = $queue['filters'];

        return [
            'counts' => [
                'mine' => $this->countOpen(['assigned_to' => $me], $me),
                'unassigned' => $this->countOpen(['unassigned' => 1], $me),
                'all' => $this->countOpen([], $me),
            ],
            'views' => array_map(
                fn (array $view): array => [
                    'id' => $view['id'],
                    'name' => $view['name'],
                    'count' => $this->countOpen($view['filters'], $me),
                    'filters' => $view['filters'],
                ],
                $this->views($me),
            ),
            // Each stat is the list the tile opens: the queue plus the tile's filters.
            'stats' => [
                'open' => $this->filters->count(array_merge($base, ['status' => 'open']), $me),
                'urgent' => $this->filters->count(array_merge($base, ['priority' => 'urgent', 'open' => 1]), $me),
                'sla_at_risk' => $this->filters->count(array_merge($base, ['sla_at_risk' => 1]), $me),
                'resolved_today' => $this->filters->count(array_merge($base, ['status' => 'resolved', 'resolved_today' => 1]), $me),
            ],
        ];
    }

    /** Unresolved tickets, unless the filters ask for a status themselves. */
    private function countOpen(array $filters, int|string $me): int
    {
        if (empty($filters['status'])) {
            $filters['open'] = 1;
        }

        return $this->filters->count($filters, $me);
    }

    private function viewQueue(array $view): array
    {
        return ['key' => 'view:'.$view['id'], 'title' => $view['name'], 'filters' => $view['filters']];
    }

    private function workspaceQueue(string $key, int|string $me): array
    {
        return match ($key) {
            'mine' => ['key' => 'mine', 'title' => self::QUEUE_TITLES['mine'], 'filters' => ['assigned_to' => $me]],
            'unassigned' => ['key' => 'unassigned', 'title' => self::QUEUE_TITLES['unassigned'], 'filters' => ['unassigned' => 1]],
            default => ['key' => 'all', 'title' => self::QUEUE_TITLES['all'], 'filters' => []],
        };
    }

    /**
     * Filters as strings, without empty values or order, for comparing a URL with a view.
     *
     * @return array<string, string>
     */
    private function comparable(array $filters): array
    {
        $clean = [];
        foreach ($filters as $key => $value) {
            if ('' === $value || null === $value || false === $value || \in_array($key, self::PRESENTATION_KEYS, true)) {
                continue;
            }
            if (\is_array($value)) {
                $value = array_map('strval', $value);
                sort($value);
                $clean[$key] = implode(',', $value);
            } else {
                $clean[$key] = (string) (true === $value ? 1 : $value);
            }
        }
        ksort($clean);

        return $clean;
    }
}
