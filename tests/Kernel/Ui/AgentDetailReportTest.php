<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\SatisfactionRating;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * Reports/AgentDetail, the per-agent screen the agent ranking links to, with
 * the props the shared page declares: the period's KPIs against the period of
 * the same length before it, response percentiles, and the productivity,
 * workload and CSAT charts as `{label, value}` rows.
 */
final class AgentDetailReportTest extends EscalatedWebTestCase
{
    private const DECLARED = [
        'agent', 'avg_frt', 'avg_resolution', 'csat_breakdown', 'csat_score', 'period_days', 'prev_avg_frt',
        'prev_avg_resolution', 'prev_csat_score', 'prev_resolved_tickets', 'prev_total_tickets', 'productivity',
        'resolved_tickets', 'response_percentiles', 'total_tickets', 'workload',
    ];

    private KernelBrowser $client;
    private TestUser $agent;
    private TestUser $admin;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $this->agent = new TestUser('ada@example.com');
        $em = static::entityManager();
        $em->persist($this->admin);
        $em->persist($this->agent);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $this->agent->getId()));
        $em->flush();
    }

    public function testTheRouteThePagesLinkByExists(): void
    {
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();
        self::assertNotNull($routes->get('escalated.admin.reports.agent-detail'));
    }

    public function testSendsTheDeclaredPropsAndTheFiguresBehindThem(): void
    {
        $em = static::entityManager();
        $agentId = (int) $this->agent->getId();
        $created = (new \DateTimeImmutable('-2 days'))->setTime(10, 0);

        $tickets = static::getContainer()->get(TicketService::class);
        \assert($tickets instanceof TicketService);
        $answered = $tickets->create(['subject' => 'Answered']);
        $waiting = $tickets->create(['subject' => 'Waiting']);
        $previous = $tickets->create(['subject' => 'Previous']);
        $tickets->create(['subject' => "Someone else's"]);
        $answered->setAssignedTo($agentId)->setFirstResponseAt($created->modify('+2 hours'))->setResolvedAt($created->modify('+4 hours'));
        $waiting->setAssignedTo($agentId)->setFirstResponseAt($created->modify('+4 hours'));
        $previous->setAssignedTo($agentId);
        $em->persist((new SatisfactionRating())->setTicket($answered)->setRating(4));
        $em->persist((new Reply())->setTicket($answered)->setAuthorId($agentId)->setBody('On it'));
        $em->persist((new Reply())->setTicket($answered)->setAuthorId($agentId)->setBody('internal')->setIsInternalNote(true));
        $em->flush();

        $this->setCreatedAt(Ticket::class, [$answered->getId(), $waiting->getId()], $created);
        $this->setCreatedAt(Ticket::class, [$previous->getId()], new \DateTimeImmutable('-40 days'));
        $em->createQuery('UPDATE '.Reply::class.' r SET r.createdAt = :at')->setParameter('at', $created->modify('+2 hours'))->execute();
        $em->clear();

        $page = $this->visit('/support/admin/reports/agent/'.$agentId.'/detail?days=30');
        self::assertSame('Escalated/Admin/Reports/AgentDetail', $page['component']);
        $p = $page['props'];
        $sent = array_values(array_diff(array_keys($p), ['errors', 'auth', 'flash', 'escalated', 'ziggy', 'current_user', 'plugin_ui', 'locale', 'translations']));
        sort($sent);
        self::assertSame(self::DECLARED, $sent);

        $day = $created->format('Y-m-d');
        self::assertSame(30, $p['period_days']);
        self::assertSame(['id' => $agentId, 'name' => 'ada@example.com', 'email' => 'ada@example.com'], $p['agent']);
        self::assertSame(2, $p['total_tickets']);
        self::assertSame(1, $p['resolved_tickets']);
        self::assertSame(3.0, (float) $p['avg_frt']);
        self::assertSame(4.0, (float) $p['avg_resolution']);
        self::assertSame(4.0, (float) $p['csat_score']);
        self::assertSame(['p50', 'p75', 'p90', 'p95', 'p99'], array_keys($p['response_percentiles']));
        self::assertSame(3.0, (float) $p['response_percentiles']['p50']);
        self::assertSame(4.0, (float) $p['response_percentiles']['p99']);
        // One public reply and one resolution that day; the internal note is not counted.
        self::assertSame([['label' => $day, 'value' => 2, 'values' => [1, 1]]], $p['productivity']);
        self::assertSame([['label' => $day, 'value' => 2]], $p['workload']);
        self::assertSame([['label' => '4 stars', 'value' => 1]], $p['csat_breakdown']);
        self::assertSame(1, $p['prev_total_tickets']);
        self::assertSame(0, $p['prev_resolved_tickets']);
        self::assertNull($p['prev_csat_score']);
    }

    public function testAnAgentWithNoTickets(): void
    {
        $p = $this->visit('/support/admin/reports/agent/'.$this->agent->getId().'/detail')['props'];

        self::assertSame(30, $p['period_days']);
        self::assertSame(0, $p['total_tickets']);
        self::assertNull($p['csat_score']);
        self::assertEquals(['p50' => 0, 'p75' => 0, 'p90' => 0, 'p95' => 0, 'p99' => 0], $p['response_percentiles']);
        self::assertSame([], $p['productivity']);
        self::assertSame([], $p['workload']);
        self::assertSame([], $p['csat_breakdown']);
        foreach (['agent_id', 'metrics', 'percentiles'] as $old) {
            self::assertArrayNotHasKey($old, $p);
        }
    }

    public function testAdminsOnly(): void
    {
        $this->client->loginUser($this->agent);
        $this->client->request('GET', '/support/admin/reports/agent/'.$this->agent->getId().'/detail', server: ['HTTP_X_INERTIA' => 'true']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param list<int|null> $ids
     */
    private function setCreatedAt(string $entity, array $ids, \DateTimeImmutable $at): void
    {
        static::entityManager()->createQuery('UPDATE '.$entity.' e SET e.createdAt = :at WHERE e.id IN (:ids)')
            ->setParameter('at', $at)
            ->setParameter('ids', $ids)
            ->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(string $uri): array
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }
}
