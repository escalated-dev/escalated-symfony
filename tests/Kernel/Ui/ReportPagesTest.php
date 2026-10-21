<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\SatisfactionRating;
use Escalated\Symfony\Entity\SlaPolicy;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * The report screens against what the shared pages read (escalated-laravel's
 * Admin\ReportController): the overview the Reports link opens got none of
 * its figures, and Reports/Dashboard, AgentMetrics, SlaReport, CsatReport and
 * Comparison, with the `escalated.admin.reports*` names they link each other
 * by, did not exist.
 */
final class ReportPagesTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;
    private TestUser $agent;

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
        $em->flush();
        $this->client->loginUser($admin);

        $policy = (new SlaPolicy())->setName('Standard')->setFirstResponseHours(['low' => 1])->setResolutionHours(['low' => 4]);
        $em->persist($policy);
        $em->flush();

        $tickets = static::getContainer()->get(TicketService::class);
        \assert($tickets instanceof TicketService);
        $met = $tickets->create(['subject' => 'Met', 'priority' => 'high']);
        $breached = $tickets->create(['subject' => 'Breached', 'priority' => 'low']);
        $met->setSlaPolicy($policy)->setAssignedTo((int) $this->agent->getId())->setFirstResponseAt(new \DateTimeImmutable('+2 hours'));
        $breached->setSlaPolicy($policy)->setAssignedTo((int) $this->agent->getId())->setSlaFirstResponseBreached(true);
        $em->flush();
        $tickets->changeStatus($met, Ticket::STATUS_RESOLVED);
        $em->persist((new SatisfactionRating())->setTicket($met)->setRating(4));
        $em->flush();
    }

    public function testTheNamesThePagesLinkByExist(): void
    {
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();
        foreach (['escalated.admin.reports', 'escalated.admin.reports.dashboard', 'escalated.admin.reports.agents', 'escalated.admin.reports.sla', 'escalated.admin.reports.csat', 'escalated.admin.reports.comparison', 'escalated.admin.reports.index', 'escalated.admin.reports.export'] as $name) {
            self::assertNotNull($routes->get($name), $name);
        }
    }

    public function testOverview(): void
    {
        $page = $this->visit('/support/admin/reports');
        self::assertSame('Escalated/Admin/Reports', $page['component']);
        $p = $page['props'];
        self::assertSame(30, $p['period_days']);
        self::assertSame(2, $p['total_tickets']);
        self::assertSame(1, $p['resolved_tickets']);
        self::assertSame(2.0, (float) $p['avg_first_response_hours']);
        self::assertSame(1, $p['sla_breach_count']);
        self::assertSame(['open' => 1, 'resolved' => 1], $p['by_status']);
        self::assertSame(['high' => 1, 'low' => 1], $p['by_priority']);
        self::assertEquals(['average' => 4.0, 'total' => 1, 'breakdown' => ['4' => 1]], $p['csat']);
    }

    public function testDashboardAndAgentMetrics(): void
    {
        $p = $this->visit('/support/admin/reports/dashboard?days=7')['props'];
        self::assertSame(['period_days', 'volume', 'by_status', 'by_priority', 'avg_response_hours', 'avg_resolution_hours', 'sla_compliance', 'csat_average', 'agent_performance'], array_keys($p));
        self::assertSame(7, $p['period_days']);
        self::assertSame(2, $p['volume'][0]['value']);
        self::assertSame(['label', 'value'], array_keys($p['by_status'][0]));
        self::assertSame(50.0, (float) $p['sla_compliance']);
        $agent = $p['agent_performance'][0];
        self::assertSame(['agent_id', 'agent_name', 'total_tickets', 'resolved_tickets', 'avg_response_hours', 'avg_resolution_hours'], array_keys($agent));
        self::assertSame('agent@example.com', $agent['agent_name']);
        self::assertSame(2, $agent['total_tickets']);

        $page = $this->visit('/support/admin/reports/agents');
        self::assertSame('Escalated/Admin/Reports/AgentMetrics', $page['component']);
        self::assertSame($this->agent->getId(), $page['props']['agents'][0]['agent_id']);
    }

    public function testSlaCsatAndComparison(): void
    {
        $page = $this->visit('/support/admin/reports/sla');
        self::assertSame('Escalated/Admin/Reports/SlaReport', $page['component']);
        self::assertSame([['policy_name' => 'Standard', 'total' => 2, 'met' => 1, 'breached' => 1]], $page['props']['compliance_by_policy']);
        self::assertSame(['Breached'], array_column($page['props']['breaches'], 'subject'));
        self::assertTrue($page['props']['breaches'][0]['sla_first_response_breached']);
        self::assertSame('agent@example.com', $page['props']['breaches'][0]['assignee']['name']);

        $page = $this->visit('/support/admin/reports/csat');
        self::assertSame('Escalated/Admin/Reports/CsatReport', $page['component']);
        self::assertSame(1, $page['props']['total_ratings']);
        self::assertSame(50.0, (float) $page['props']['response_rate']);
        self::assertSame(['agent_id', 'agent_name', 'avg_rating', 'total_ratings'], array_keys($page['props']['by_agent'][0]));
        self::assertSame(['label', 'value'], array_keys($page['props']['over_time'][0]));

        $page = $this->visit('/support/admin/reports/comparison');
        self::assertSame('Escalated/Admin/Reports/Comparison', $page['component']);
        foreach (['current', 'previous'] as $side) {
            self::assertSame(['total_tickets', 'resolved_tickets', 'avg_frt', 'avg_resolution', 'sla_compliance', 'csat', 'breach_count', 'volume_trend'], array_keys($page['props'][$side]));
        }
        self::assertSame(2, $page['props']['current']['total_tickets']);
        self::assertSame(0, $page['props']['previous']['total_tickets']);
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
