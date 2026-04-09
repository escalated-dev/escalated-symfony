<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

class ExportService
{
    public const EXPORTABLE_REPORTS = [
        'slaBreachTrends',
        'frtDistribution',
        'frtTrends',
        'frtByAgent',
        'resolutionTimeDistribution',
        'resolutionTimeTrends',
        'agentPerformanceRanking',
        'periodComparison',
    ];

    public function __construct(
        private readonly AdvancedReportingService $reporting,
    ) {
    }

    public function exportCsv(string $reportType, \DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $this->validateReportType($reportType);
        $data = $this->reporting->{$reportType}($from, $to);
        $rows = $this->flattenForCsv($data);

        return $this->generateCsv($rows);
    }

    public function exportJson(string $reportType, \DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $this->validateReportType($reportType);
        $data = $this->reporting->{$reportType}($from, $to);

        return json_encode($data, JSON_PRETTY_PRINT);
    }

    public function exportCohortCsv(string $dimension, \DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $data = $this->reporting->cohortAnalysis($dimension, $from, $to);

        return $this->generateCsv($this->flattenForCsv($data));
    }

    public function exportCohortJson(string $dimension, \DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $data = $this->reporting->cohortAnalysis($dimension, $from, $to);

        return json_encode($data, JSON_PRETTY_PRINT);
    }

    private function validateReportType(string $reportType): void
    {
        if (!\in_array($reportType, self::EXPORTABLE_REPORTS, true)) {
            throw new \InvalidArgumentException("Unknown report type: {$reportType}");
        }
    }

    private function flattenForCsv(mixed $data): array
    {
        if (\is_array($data) && array_is_list($data)) {
            return array_map(fn ($row) => $this->flattenHash($row), $data);
        }
        if (\is_array($data)) {
            return [$this->flattenHash($data)];
        }

        return [];
    }

    private function flattenHash(mixed $hash, string $prefix = ''): array
    {
        if (!\is_array($hash)) {
            return [];
        }
        $result = [];
        foreach ($hash as $key => $value) {
            $fullKey = $prefix ? "{$prefix}_{$key}" : (string) $key;
            if (\is_array($value) && !array_is_list($value)) {
                $result = array_merge($result, $this->flattenHash($value, $fullKey));
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }

    private function generateCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }
        $headers = array_keys($rows[0]);
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, array_map(fn ($h) => $row[$h] ?? '', $headers));
        }
        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return $content;
    }
}
