<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Rendering\ReportPages;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\AdvancedReportingService;
use Escalated\Symfony\Service\ExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exposes the existing AdvancedReportingService + ExportService over an
 * admin API. Mirrors the report surface of the Laravel reference host
 * (src/Http/Controllers/Admin/ReportController.php): a landing dashboard
 * rendered through Inertia, one JSON endpoint per report family, and a
 * CSV/JSON export endpoint.
 *
 * All actions are gated on ESCALATED_ADMIN. The analytics + export logic
 * already lives in the services; this controller only translates the
 * `period` (days) query param into a date range and shapes the response.
 */
#[Route('/admin/reports', name: 'escalated.admin.')]
class ReportController extends AbstractController
{
    /** Upper bound on the reporting window; AdvancedReportingService clamps its date series to 90 days. */
    private const MAX_PERIOD_DAYS = 90;

    private const DEFAULT_PERIOD_DAYS = 30;

    public function __construct(
        private readonly AdvancedReportingService $reporting,
        private readonly ExportService $exportService,
        private readonly UiRendererInterface $renderer,
        private readonly ?ReportPages $pages = null,
    ) {
    }

    /**
     * Escalated/Admin/Reports, the overview the Reports link opens, with
     * escalated-laravel's props. The earlier overview keys (`comparison`,
     * `sla_trends`, `agent_ranking`, `exportable_reports`) are still sent for
     * hosts that read them.
     */
    #[Route('', name: 'reports', methods: ['GET'])]
    #[Route('', name: 'reports.index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        [$from, $to, $days] = $this->range($request);

        return $this->renderer->render('Escalated/Admin/Reports', [
            ...($this->pages?->overview($days) ?? ['period_days' => $days]),
            'comparison' => $this->reporting->periodComparison($from, $to),
            'sla_trends' => $this->reporting->slaBreachTrends($from, $to),
            'agent_ranking' => $this->reporting->agentPerformanceRanking($from, $to),
            'exportable_reports' => ExportService::EXPORTABLE_REPORTS,
        ]);
    }

    #[Route('/dashboard', name: 'reports.dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Reports/Dashboard', $this->pages()->dashboard($this->range($request)[2]));
    }

    #[Route('/agents', name: 'reports.agents', methods: ['GET'])]
    public function agents(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Reports/AgentMetrics', $this->pages()->agents($this->range($request)[2]));
    }

    #[Route('/sla', name: 'reports.sla', methods: ['GET'])]
    public function sla(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Reports/SlaReport', $this->pages()->sla($this->range($request)[2]));
    }

    #[Route('/csat', name: 'reports.csat', methods: ['GET'])]
    public function csat(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Reports/CsatReport', $this->pages()->csat($this->range($request)[2]));
    }

    #[Route('/comparison', name: 'reports.comparison', methods: ['GET'])]
    public function comparison(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Reports/Comparison', $this->pages()->comparison($this->range($request)[2]));
    }

    private function pages(): ReportPages
    {
        return $this->pages ?? throw new \LogicException('ReportPages is not available.');
    }

    /**
     * SLA breach trends over time (first-response vs resolution breaches).
     */
    #[Route('/sla-trends', name: 'reports.sla_trends', methods: ['GET'])]
    public function slaTrends(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        [$from, $to, $days] = $this->range($request);

        return new JsonResponse([
            'period_days' => $days,
            'trends' => $this->reporting->slaBreachTrends($from, $to),
        ]);
    }

    /**
     * First response time analytics: distribution histogram, daily trend,
     * and per-agent breakdown.
     */
    #[Route('/first-response-time', name: 'reports.first_response_time', methods: ['GET'])]
    public function firstResponseTime(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        [$from, $to, $days] = $this->range($request);

        return new JsonResponse([
            'period_days' => $days,
            'distribution' => $this->reporting->frtDistribution($from, $to),
            'trend' => $this->reporting->frtTrends($from, $to),
            'by_agent' => $this->reporting->frtByAgent($from, $to),
        ]);
    }

    /**
     * Resolution time analytics: distribution histogram + daily trend.
     */
    #[Route('/resolution-time', name: 'reports.resolution_time', methods: ['GET'])]
    public function resolutionTime(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        [$from, $to, $days] = $this->range($request);

        return new JsonResponse([
            'period_days' => $days,
            'distribution' => $this->reporting->resolutionTimeDistribution($from, $to),
            'trend' => $this->reporting->resolutionTimeTrends($from, $to),
        ]);
    }

    /**
     * Agent performance ranking (composite score).
     */
    #[Route('/agent-ranking', name: 'reports.agent_ranking', methods: ['GET'])]
    public function agentRanking(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        [$from, $to, $days] = $this->range($request);

        return new JsonResponse([
            'period_days' => $days,
            'ranking' => $this->reporting->agentPerformanceRanking($from, $to),
        ]);
    }

    /**
     * Cohort analysis by department, channel, or ticket type.
     */
    #[Route('/cohort', name: 'reports.cohort', methods: ['GET'])]
    public function cohort(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        [$from, $to, $days] = $this->range($request);
        $dimension = (string) $request->query->get('dimension', 'department');

        return new JsonResponse([
            'period_days' => $days,
            'dimension' => $dimension,
            'cohort' => $this->reporting->cohortAnalysis($dimension, $from, $to),
        ]);
    }

    /**
     * Current period vs previous period comparison for the key metrics.
     */
    #[Route('/period-comparison', name: 'reports.period_comparison', methods: ['GET'])]
    public function periodComparison(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        [$from, $to, $days] = $this->range($request);

        return new JsonResponse([
            'period_days' => $days,
            'comparison' => $this->reporting->periodComparison($from, $to),
        ]);
    }

    /**
     * Export a report as CSV (default) or JSON. `type` is one of
     * ExportService::EXPORTABLE_REPORTS, or the special value `cohort`
     * (which reads an extra `dimension` query param).
     */
    #[Route('/export/{type}', name: 'reports.export', methods: ['GET'], requirements: ['type' => '[a-zA-Z_]+'])]
    public function export(string $type, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        [$from, $to] = $this->range($request);
        $format = strtolower((string) $request->query->get('format', 'csv'));
        $asJson = 'json' === $format;

        try {
            if ('cohort' === $type) {
                $dimension = (string) $request->query->get('dimension', 'department');
                $content = $asJson
                    ? $this->exportService->exportCohortJson($dimension, $from, $to)
                    : $this->exportService->exportCohortCsv($dimension, $from, $to);
            } else {
                $content = $asJson
                    ? $this->exportService->exportJson($type, $from, $to)
                    : $this->exportService->exportCsv($type, $from, $to);
            }
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $filename = sprintf(
            '%s_report_%s.%s',
            $type,
            (new \DateTimeImmutable('now'))->format('Y-m-d_His'),
            $asJson ? 'json' : 'csv',
        );

        $response = $asJson
            ? JsonResponse::fromJsonString('' === $content ? '[]' : $content)
            : new Response($content, Response::HTTP_OK, ['Content-Type' => 'text/csv']);

        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Resolve the reporting window from the request. Accepts `period`
     * (preferred, matches the reference host) or `days` as an alias,
     * defaulting to 30 and clamped to [1, 90].
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int}
     */
    private function range(Request $request): array
    {
        $raw = $request->query->get('period', $request->query->get('days', self::DEFAULT_PERIOD_DAYS));
        $days = max(1, min(self::MAX_PERIOD_DAYS, (int) $raw));

        $to = new \DateTimeImmutable('now');
        $from = $to->modify(sprintf('-%d days', $days));

        return [$from, $to, $days];
    }
}
