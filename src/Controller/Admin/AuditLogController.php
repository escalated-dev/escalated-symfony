<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\AuditLog;
use Escalated\Symfony\Rendering\TicketPresenter;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Rendering\UserDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Escalated/Admin/AuditLog/Index, with escalated-laravel's
 * AuditLogController props: the entries newest first, 50 a page, filtered by
 * user, action, resource type and date range, plus the filter options.
 *
 * Entries are what AuditLogService has recorded. An entry's resource is its
 * `entity_type`/`entity_id`, sent as Laravel's `auditable_type`/`auditable_id`,
 * and its user is the performer, looked up among the host's users.
 */
#[Route('/admin/audit-log', name: 'escalated.admin.')]
class AuditLogController extends AbstractController
{
    private const PER_PAGE = 50;

    /** The actions escalated-laravel offers in the filter. */
    private const ACTIONS = ['created', 'updated', 'deleted'];

    private const FILTERS = ['user_id', 'action', 'auditable_type', 'date_from', 'date_to'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly TicketPresenter $presenter,
        private readonly UserDirectory $users,
    ) {
    }

    #[Route('', name: 'audit-log', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $filters = [];
        foreach (self::FILTERS as $key) {
            $value = $request->query->get($key);
            if (null !== $value && '' !== $value) {
                $filters[$key] = (string) $value;
            }
        }

        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(AuditLog::class, 'a')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');
        if (isset($filters['user_id'])) {
            $qb->andWhere('a.performerId = :user')->setParameter('user', (int) $filters['user_id']);
        }
        if (isset($filters['action'])) {
            $qb->andWhere('a.action = :action')->setParameter('action', $filters['action']);
        }
        if (isset($filters['auditable_type'])) {
            $qb->andWhere('a.entityType = :type')->setParameter('type', $filters['auditable_type']);
        }
        if (null !== ($from = self::date($filters['date_from'] ?? null))) {
            $qb->andWhere('a.createdAt >= :from')->setParameter('from', $from->setTime(0, 0));
        }
        if (null !== ($to = self::date($filters['date_to'] ?? null))) {
            $qb->andWhere('a.createdAt <= :to')->setParameter('to', $to->setTime(23, 59, 59));
        }

        $logs = $this->presenter->paginate(
            $qb,
            $request->query->getInt('page', 1),
            self::PER_PAGE,
            $this->generateUrl('escalated.admin.audit-log'),
            fn (AuditLog $log): array => $this->present($log),
            $filters,
        );

        return $this->renderer->render('Escalated/Admin/AuditLog/Index', [
            'logs' => $logs,
            'filters' => (object) $filters,
            'users' => $this->performers(),
            'actions' => self::ACTIONS,
            'resourceTypes' => $this->resourceTypes(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AuditLog $log): array
    {
        $user = null === $log->getPerformerId() ? null : $this->users->find($log->getPerformerId());

        return [
            'id' => $log->getId(),
            'user_id' => $log->getPerformerId(),
            'action' => $log->getAction(),
            'auditable_type' => $log->getEntityType(),
            'auditable_id' => $log->getEntityId(),
            'old_values' => $log->getOldValues(),
            'new_values' => $log->getNewValues(),
            'ip_address' => $log->getIpAddress(),
            'user_agent' => $log->getUserAgent(),
            'created_at' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'user' => null === $user ? null : ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']],
        ];
    }

    /**
     * Everyone who has an entry, by name: the users the log can be filtered to.
     *
     * @return list<array{id: int|string, name: string}>
     */
    private function performers(): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('DISTINCT a.performerId')
            ->from(AuditLog::class, 'a')
            ->where('a.performerId IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        $users = array_map(static fn (array $user): array => ['id' => $user['id'], 'name' => $user['name']], array_values($this->users->findMany($ids)));
        usort($users, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $users;
    }

    /**
     * @return list<string>
     */
    private function resourceTypes(): array
    {
        $types = $this->em->createQueryBuilder()
            ->select('DISTINCT a.entityType')
            ->from(AuditLog::class, 'a')
            ->getQuery()
            ->getSingleColumnResult();
        $types = array_map('strval', $types);
        sort($types);

        return $types;
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return false === $date ? null : $date;
    }
}
