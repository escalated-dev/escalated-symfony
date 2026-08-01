<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Controller\Admin;

use Escalated\Symfony\Controller\Admin\ReportController;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\AdvancedReportingService;
use Escalated\Symfony\Service\ExportService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Functional coverage for the admin Reports API.
 *
 * The Symfony bundle has no kernel-test harness, so — following the
 * pattern established by {@see UserControllerTest} — we drive the
 * controller in isolation. The reporting layer is exercised through a
 * canned {@see StubReportingService} feeding the *real* ExportService, so
 * the CSV/JSON export path runs end-to-end. Auth gating is verified by
 * overriding the single AbstractController seam (`denyAccessUnlessGranted`).
 */
class ReportControllerTest extends TestCase
{
    private CapturingUiRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new CapturingUiRenderer();
    }

    public function testIndexRendersReportsDashboardForAdmins(): void
    {
        $controller = $this->controller();
        $controller->index(new Request());

        $this->assertSame('Escalated/Admin/Reports', $this->renderer->lastPage);
        foreach (['period_days', 'comparison', 'sla_trends', 'agent_ranking', 'exportable_reports'] as $key) {
            $this->assertArrayHasKey($key, $this->renderer->lastProps);
        }
        $this->assertSame(30, $this->renderer->lastProps['period_days']);
        $this->assertContains('agentPerformanceRanking', $this->renderer->lastProps['exportable_reports']);
    }

    public function testIndexIsBlockedForNonAdmins(): void
    {
        $controller = $this->controller(isAdmin: false);

        $this->expectException(AccessDeniedException::class);
        $controller->index(new Request());
    }

    public function testSlaTrendsReturnsTrendKeys(): void
    {
        $payload = $this->decode($this->controller()->slaTrends(new Request()));

        $this->assertSame(30, $payload['period_days']);
        $this->assertArrayHasKey('trends', $payload);
        $this->assertSame('2026-01-01', $payload['trends'][0]['date']);
    }

    public function testSlaTrendsIsBlockedForNonAdmins(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->controller(isAdmin: false)->slaTrends(new Request());
    }

    public function testFirstResponseTimeReturnsDistributionTrendAndByAgent(): void
    {
        $payload = $this->decode($this->controller()->firstResponseTime(new Request()));

        $this->assertArrayHasKey('distribution', $payload);
        $this->assertArrayHasKey('trend', $payload);
        $this->assertArrayHasKey('by_agent', $payload);
        $this->assertArrayHasKey('buckets', $payload['distribution']);
        $this->assertSame(7, $payload['by_agent'][0]['agent_id']);
    }

    public function testResolutionTimeReturnsDistributionAndTrend(): void
    {
        $payload = $this->decode($this->controller()->resolutionTime(new Request()));

        $this->assertArrayHasKey('distribution', $payload);
        $this->assertArrayHasKey('trend', $payload);
    }

    public function testAgentRankingReturnsRanking(): void
    {
        $payload = $this->decode($this->controller()->agentRanking(new Request()));

        $this->assertArrayHasKey('ranking', $payload);
        $this->assertSame(7, $payload['ranking'][0]['agent_id']);
        // JsonResponse drops the zero fraction (no JSON_PRESERVE_ZERO_FRACTION), so compare loosely.
        $this->assertEquals(80.0, $payload['ranking'][0]['resolution_rate']);
    }

    public function testCohortPassesDimensionThrough(): void
    {
        $request = new Request(query: ['dimension' => 'channel']);
        $payload = $this->decode($this->controller()->cohort($request));

        $this->assertSame('channel', $payload['dimension']);
        // The stub echoes the requested dimension into the cohort name.
        $this->assertSame('channel', $payload['cohort'][0]['name']);
    }

    public function testPeriodComparisonReturnsCurrentPreviousChanges(): void
    {
        $payload = $this->decode($this->controller()->periodComparison(new Request()));

        $this->assertArrayHasKey('comparison', $payload);
        foreach (['current', 'previous', 'changes'] as $key) {
            $this->assertArrayHasKey($key, $payload['comparison']);
        }
    }

    public function testPeriodParamIsHonouredAndClamped(): void
    {
        $this->assertSame(45, $this->decode($this->controller()->slaTrends(new Request(query: ['period' => '45'])))['period_days']);
        $this->assertSame(90, $this->decode($this->controller()->slaTrends(new Request(query: ['period' => '500'])))['period_days']);
        $this->assertSame(1, $this->decode($this->controller()->slaTrends(new Request(query: ['period' => '0'])))['period_days']);
        // `days` is honoured as an alias for `period`.
        $this->assertSame(14, $this->decode($this->controller()->slaTrends(new Request(query: ['days' => '14'])))['period_days']);
    }

    public function testExportCsvReturnsCsvAttachment(): void
    {
        $response = $this->controller()->export('agentPerformanceRanking', new Request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.csv', (string) $response->headers->get('Content-Disposition'));
        $body = (string) $response->getContent();
        $this->assertStringContainsString('agent_id', $body);
        $this->assertStringContainsString('composite_score', $body);
        $this->assertStringContainsString('7', $body);
    }

    public function testExportJsonReturnsJsonAttachment(): void
    {
        $request = new Request(query: ['format' => 'json']);
        $response = $this->controller()->export('agentPerformanceRanking', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertStringContainsString('.json', (string) $response->headers->get('Content-Disposition'));
        $decoded = json_decode((string) $response->getContent(), true);
        $this->assertSame(7, $decoded[0]['agent_id']);
    }

    public function testExportCohortUsesDimension(): void
    {
        $request = new Request(query: ['format' => 'json', 'dimension' => 'channel']);
        $response = $this->controller()->export('cohort', $request);

        $decoded = json_decode((string) $response->getContent(), true);
        $this->assertSame('channel', $decoded[0]['name']);
    }

    public function testExportUnknownTypeReturnsBadRequest(): void
    {
        $response = $this->controller()->export('does_not_exist', new Request());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $decoded = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $decoded);
    }

    public function testExportIsBlockedForNonAdmins(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->controller(isAdmin: false)->export('agentPerformanceRanking', new Request());
    }

    public function testRoutesExposeExpectedNames(): void
    {
        $ref = new \ReflectionClass(ReportController::class);
        $expected = ['index', 'sla_trends', 'first_response_time', 'resolution_time', 'agent_ranking', 'cohort', 'period_comparison', 'export'];
        $found = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attr) {
                $found[] = $attr->newInstance()->name;
            }
        }

        foreach ($expected as $name) {
            $this->assertContains($name, $found, 'Missing route name '.$name);
        }
    }

    public function testControllerHasAdminReportsPrefix(): void
    {
        $ref = new \ReflectionClass(ReportController::class);
        $attrs = $ref->getAttributes(Route::class);
        $this->assertNotEmpty($attrs);

        /** @var Route $route */
        $route = $attrs[0]->newInstance();
        $this->assertSame('/admin/reports', $route->path);
        $this->assertSame('escalated.admin.reports.', $route->name);
    }

    /** @return array<string, mixed> */
    private function decode(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function controller(bool $isAdmin = true): TestableReportController
    {
        $reporting = new StubReportingService();
        $export = new ExportService($reporting);

        $controller = new TestableReportController($reporting, $export, $this->renderer);
        $controller->setIsAdmin($isAdmin);

        return $controller;
    }
}

/**
 * Canned reporting layer. Bypasses the Doctrine-backed constructor and
 * returns fixed, recognisable shapes for every public report method, so
 * the controller and the real ExportService can be exercised without a
 * database.
 */
class StubReportingService extends AdvancedReportingService
{
    public function __construct()
    {
        // Intentionally skip parent::__construct(): no EntityManager needed.
    }

    public function slaBreachTrends(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return [['date' => '2026-01-01', 'frt_breaches' => 3, 'resolution_breaches' => 1]];
    }

    public function frtDistribution(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return ['buckets' => [['range' => '0-1', 'count' => 2]], 'stats' => ['avg' => 1.5, 'count' => 2], 'percentiles' => ['p50' => 1.0]];
    }

    public function frtTrends(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return [['date' => '2026-01-01', 'avg_hours' => 2.0, 'count' => 1, 'percentiles' => []]];
    }

    public function frtByAgent(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return [['agent_id' => 7, 'avg_hours' => 2.0, 'count' => 1, 'percentiles' => []]];
    }

    public function resolutionTimeDistribution(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return ['buckets' => [['range' => '0-24', 'count' => 1]], 'stats' => ['avg' => 12.0, 'count' => 1], 'percentiles' => ['p50' => 12.0]];
    }

    public function resolutionTimeTrends(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return [['date' => '2026-01-01', 'avg_hours' => 10.0, 'count' => 1, 'percentiles' => []]];
    }

    public function agentPerformanceRanking(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return [['agent_id' => 7, 'total_tickets' => 5, 'resolved_count' => 4, 'resolution_rate' => 80.0, 'composite_score' => 24.0]];
    }

    public function cohortAnalysis(string $dimension, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return [['name' => $dimension, 'total' => 3, 'resolved' => 2, 'resolution_rate' => 66.7]];
    }

    public function periodComparison(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return [
            'current' => ['total_created' => 10, 'total_resolved' => 8, 'resolution_rate' => 80.0],
            'previous' => ['total_created' => 6, 'total_resolved' => 5, 'resolution_rate' => 83.3],
            'changes' => ['total_created' => 66.7, 'total_resolved' => 60.0, 'resolution_rate' => -4.0],
        ];
    }
}

class CapturingUiRenderer implements UiRendererInterface
{
    public string $lastPage = '';

    /** @var array<string, mixed> */
    public array $lastProps = [];

    public function render(string $page, array $props = []): Response
    {
        $this->lastPage = $page;
        $this->lastProps = $props;

        return new JsonResponse(['component' => $page, 'props' => $props]);
    }
}

/**
 * Subclass that stubs the one AbstractController seam the controller
 * relies on (`denyAccessUnlessGranted`), throwing when the caller is not
 * an admin — mirroring the production voter without booting security.
 */
class TestableReportController extends ReportController
{
    private bool $isAdmin = true;

    public function setIsAdmin(bool $value): void
    {
        $this->isAdmin = $value;
    }

    protected function denyAccessUnlessGranted(mixed $attribute, mixed $subject = null, string $message = 'Access Denied.'): void
    {
        if ('ESCALATED_ADMIN' === $attribute && !$this->isAdmin) {
            throw new AccessDeniedException($message);
        }
    }
}
