<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Rendering\TicketPresenter;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\UserKeyResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Escalated/Agent/Dashboard, with escalated-laravel's props: `stats`,
 * `recentTickets`, `needsAttention` and `myPerformance`.
 */
#[Route('/agent', name: 'escalated.agent.')]
class DashboardController extends AbstractController
{
    private const CLOSED = [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketPresenter $presenter,
        private readonly UserKeyResolver $userKeys,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $user = $this->getUser();
        $me = $user instanceof UserInterface ? $this->userKeys->resolve($user) : null;
        $today = new \DateTimeImmutable('today');
        $week = new \DateTimeImmutable('monday this week');

        $rows = fn (QueryBuilder $qb, int $limit): array => array_map(
            fn (Ticket $t): array => $this->presenter->row($t),
            $qb->setMaxResults($limit)->getQuery()->getResult(),
        );

        return $this->renderer->render('Escalated/Agent/Dashboard', [
            'stats' => [
                'open' => $this->count($this->open()),
                'my_assigned' => null === $me ? 0 : $this->count($this->open()->andWhere('t.assignedTo = :me')->setParameter('me', $me)),
                'unassigned' => $this->count($this->open()->andWhere('t.assignedTo IS NULL')),
                'sla_breached' => $this->count($this->open()->andWhere('t.slaFirstResponseBreached = true OR t.slaResolutionBreached = true')),
                'resolved_today' => $this->count($this->tickets()->andWhere('t.resolvedAt >= :today')->setParameter('today', $today)),
            ],
            'recentTickets' => $rows($this->tickets()->orderBy('t.createdAt', 'DESC'), 10),
            'needsAttention' => [
                'sla_breaching' => $rows($this->open()->andWhere('t.slaFirstResponseBreached = true OR t.slaResolutionBreached = true')->orderBy('t.createdAt', 'DESC'), 5),
                'unassigned_urgent' => $rows($this->open()->andWhere('t.assignedTo IS NULL')->andWhere('t.priority IN (:urgent)')->setParameter('urgent', [Ticket::PRIORITY_URGENT, Ticket::PRIORITY_CRITICAL])->orderBy('t.createdAt', 'DESC'), 5),
            ],
            'myPerformance' => [
                'resolved_this_week' => null === $me ? 0 : $this->count(
                    $this->tickets()->andWhere('t.assignedTo = :me')->andWhere('t.resolvedAt >= :week')->setParameter('me', $me)->setParameter('week', $week),
                ),
            ],
        ]);
    }

    private function tickets(): QueryBuilder
    {
        return $this->em->getRepository(Ticket::class)->createQueryBuilder('t')->where('t.deletedAt IS NULL');
    }

    private function open(): QueryBuilder
    {
        return $this->tickets()->andWhere('t.status NOT IN (:closed)')->setParameter('closed', self::CLOSED);
    }

    private function count(QueryBuilder $qb): int
    {
        return (int) $qb->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
    }
}
