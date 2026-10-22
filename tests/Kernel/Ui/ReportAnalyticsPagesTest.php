<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\SatisfactionRating;
use Escalated\Symfony\Entity\SlaPolicy;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * The analytics report screens (Reports/SlaTrends, ResponseTimes,
 * ResolutionTimes, AgentRanking and Cohorts) against the props
 * escalated-laravel's Admin\ReportController passes them, at the paths and
 * `escalated.admin.reports.*` names the pages link each other by.
 */
final class ReportAnalyticsPagesTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;
    private TestUser $agent;
    private Ticket $met;
    private Ticket $atRisk;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $this->agent = new TestUser('agent@example.com');
        $em = static::entityManager();
        $em->persist($admin);
        $em->persist($this->agent);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $this->agent->getId()));

        $policy = (new SlaPolicy())->setName('Standard')->setFirstResponseHours(['low' => 1])->setResolutionHours(['low' => 4]);
        $billing = (new Department())->setName('Billing')->setSlug('billing');
        $vip = (new Tag())->setName('vip')->setSlug('vip');
        $em->persist($policy);
        $em->persist($billing);
        $em->persist($vip);
        $em->flush();
        $this->client->loginUser($admin);

        $tickets = static::getContainer()->get(TicketService::class);
        \assert($tickets instanceof TicketService);
        $this->met = $tickets->create(['subject' => 'Met', 'priority' => 'high', 'ticket_type' => 'question']);
        $breached = $tickets->create(['subject' => 'Breached', 'priority' => 'low']);
        $this->atRisk = $tickets->create(['subject' => 'At risk', 'priority' => 'medium']);

        $this->met->setSlaPolicy($policy)->setAssignedTo((int) $this->agent->getId())->setDepartment($billing)->setChannel('email')
            ->setFirstResponseAt(new \DateTimeImmutable('+2 hours'));
        $this->met->addTag($vip);
        $breached->setSlaPolicy($policy)->setAssignedTo((int) $this->agent->getId())->setSlaFirstResponseBreached(true)
            ->setFirstResponseDueAt(null)->setResolutionDueAt(null);
        $this->atRisk->setFirstResponseDueAt(new \DateTimeImmutable('+3 hours'))->setResolutionDueAt(null);
        $em->flush();
        $tickets->changeStatus($this->met, Ticket::STATUS_RESOLVED);
        $em->persist((new SatisfactionRating())->setTicket($this->met)->setRating(4));
        $em->flush();
    }

    public function testTheNamesThePagesLinkByExist(): void
    {
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();
        foreach ([
            'escalated.admin.reports.sla-trends' => '/admin/reports/sla-trends',
            'escalated.admin.reports.response-times' => '/admin/reports/response-times',
            'escalated.admin.reports.resolution-times' => '/admin/reports/resolution-times',
            'escalated.admin.reports.agent-ranking' => '/admin/reports/agent-ranking',
            'escalated.admin.reports.cohorts' => '/admin/reports/cohorts',
        ] as $name => $path) {
            $route = $routes->get($name);
            self::assertNotNull($route, $name);
            self::assertStringEndsWith($path, $route->getPath(), $name);
        }
    }

    public function testSlaTrends(): void
    {
        $page = $this->visit('/support/admin/reports/sla-trends?days=7');
        self::assertSame('Escalated/Admin/Reports/SlaTrends', $page['component']);
        $p = $page['props'];
        self::assertSame(['period_days', 'breach_trend', 'breach_by_type_trend', 'breach_by_department', 'breach_by_priority', 'at_risk_tickets', 'total_breaches', 'breach_rate', 'first_response_breaches', 'resolution_breaches'], array_keys($p));
        self::assertSame(7, $p['period_days']);
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        self::assertSame([['label' => $today, 'value' => 1]], $p['breach_trend']);
        self::assertSame([['label' => $today, 'values' => [1, 0]]], $p['breach_by_type_trend']);
        self::assertEquals([['label' => 'Billing', 'value' => 0], ['label' => 'Unassigned', 'value' => 100]], $p['breach_by_department']);
        self::assertEquals([['label' => 'high', 'value' => 0], ['label' => 'low', 'value' => 100]], $p['breach_by_priority']);
        self::assertSame(1, $p['total_breaches']);
        self::assertEquals(33.3, $p['breach_rate']);
        self::assertSame(1, $p['first_response_breaches']);
        self::assertSame(0, $p['resolution_breaches']);

        self::assertCount(1, $p['at_risk_tickets']);
        $risk = $p['at_risk_tickets'][0];
        self::assertSame(['id', 'reference', 'subject', 'hours_remaining'], array_keys($risk));
        self::assertSame($this->atRisk->getId(), $risk['id']);
        self::assertSame($this->atRisk->getReference(), $risk['reference']);
        self::assertEqualsWithDelta(3.0, $risk['hours_remaining'], 0.1);
    }

    public function testResponseTimes(): void
    {
        $page = $this->visit('/support/admin/reports/response-times');
        self::assertSame('Escalated/Admin/Reports/ResponseTimes', $page['component']);
        $p = $page['props'];
        self::assertSame(['period_days', 'avg_frt', 'median_frt', 'p90_frt', 'pct_under_target', 'target_hours', 'distribution', 'trend', 'by_agent', 'by_department', 'by_priority'], array_keys($p));
        self::assertSame(30, $p['period_days']);
        self::assertEquals(2.0, $p['avg_frt']);
        self::assertEquals(2.0, $p['median_frt']);
        self::assertEquals(2.0, $p['p90_frt']);
        self::assertEquals(100.0, $p['pct_under_target']);
        self::assertSame(4, $p['target_hours']);
        self::assertSame(['<1h', '1-4h', '4-8h', '8-24h', '>24h'], array_column($p['distribution'], 'label'));
        self::assertSame([0, 1, 0, 0, 0], array_column($p['distribution'], 'value'));
        self::assertEquals([['label' => (new \DateTimeImmutable())->format('Y-m-d'), 'value' => 2.0]], $p['trend']);
        self::assertEquals([['agent_id' => $this->agent->getId(), 'agent_name' => 'agent@example.com', 'count' => 1, 'avg' => 2.0, 'median' => 2.0, 'p90' => 2.0]], $p['by_agent']);
        self::assertEquals([['label' => 'Billing', 'value' => 2.0]], $p['by_department']);
        self::assertEquals([['label' => 'high', 'value' => 2.0]], $p['by_priority']);
    }

    public function testResolutionTimes(): void
    {
        $page = $this->visit('/support/admin/reports/resolution-times');
        self::assertSame('Escalated/Admin/Reports/ResolutionTimes', $page['component']);
        $p = $page['props'];
        self::assertSame(['period_days', 'avg_resolution', 'median_resolution', 'p90_resolution', 'pct_under_target', 'target_hours', 'distribution', 'trend', 'by_agent', 'by_department', 'by_channel'], array_keys($p));
        self::assertEquals(0.0, $p['avg_resolution']);
        self::assertEquals(100.0, $p['pct_under_target']);
        self::assertSame(24, $p['target_hours']);
        self::assertSame(['<4h', '4-8h', '8-24h', '1-3d', '3-7d', '>7d'], array_column($p['distribution'], 'label'));
        self::assertSame([1, 0, 0, 0, 0, 0], array_column($p['distribution'], 'value'));
        self::assertSame(['agent_id', 'agent_name', 'count', 'avg', 'median', 'p90'], array_keys($p['by_agent'][0]));
        self::assertEquals([['label' => 'Billing', 'value' => 0.0]], $p['by_department']);
        self::assertEquals([['label' => 'email', 'value' => 0.0]], $p['by_channel']);
    }

    public function testAgentRanking(): void
    {
        $page = $this->visit('/support/admin/reports/agent-ranking');
        self::assertSame('Escalated/Admin/Reports/AgentRanking', $page['component']);
        self::assertSame(['period_days', 'agents'], array_keys($page['props']));
        self::assertEquals([[
            'agent_id' => $this->agent->getId(),
            'agent_name' => 'agent@example.com',
            'volume' => 2,
            'resolution_rate' => 50.0,
            'avg_frt' => 2.0,
            'avg_resolution' => 0.0,
            'csat' => 4.0,
            // 50 * .30 + (100 - 2 * 5) * .25 + 4 * 20 * .25 + min(100, 2 * 2) * .20
            'composite_score' => 58.3,
        ]], $page['props']['agents']);
    }

    public function testCohorts(): void
    {
        $page = $this->visit('/support/admin/reports/cohorts');
        self::assertSame('Escalated/Admin/Reports/Cohorts', $page['component']);
        $p = $page['props'];
        self::assertSame(['period_days', 'by_tag', 'by_department', 'by_channel', 'by_type', 'by_priority'], array_keys($p));
        self::assertEquals([['name' => 'vip', 'volume' => 1, 'avg_resolution' => 0.0, 'breach_rate' => 0, 'csat' => 4.0]], $p['by_tag']);
        self::assertSame(['Billing', 'Unassigned'], array_column($p['by_department'], 'name'));
        self::assertSame([1, 2], array_column($p['by_department'], 'volume'));
        self::assertEquals(50.0, $p['by_department'][1]['breach_rate']);
        self::assertSame(['email', 'unknown'], array_column($p['by_channel'], 'name'));
        self::assertSame(['question', 'unspecified'], array_column($p['by_type'], 'name'));
        self::assertSame(['high', 'low', 'medium'], array_column($p['by_priority'], 'name'));
        foreach (['by_tag', 'by_department', 'by_channel', 'by_type', 'by_priority'] as $key) {
            self::assertSame(['name', 'volume', 'avg_resolution', 'breach_rate', 'csat'], array_keys($p[$key][0]), $key);
        }
    }

    public function testTheJsonEndpointsAtTheSharedPathsStillAnswerJsonClients(): void
    {
        $this->client->request('GET', '/support/admin/reports/sla-trends', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('trends', $body);

        $this->client->request('GET', '/support/admin/reports/agent-ranking', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('ranking', $body);
    }

    public function testAgentsCannotOpenThem(): void
    {
        $this->client->loginUser($this->agent);
        $this->client->request('GET', '/support/admin/reports/cohorts', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }
}
