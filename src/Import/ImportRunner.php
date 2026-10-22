<?php

declare(strict_types=1);

namespace Escalated\Symfony\Import;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\ImportJob;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs (or resumes) one import job by id. A run that throws marks the job
 * failed; resuming it continues from its saved cursors.
 */
class ImportRunner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportService $imports,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function run(string $jobId): void
    {
        $job = $this->em->find(ImportJob::class, $jobId);
        if (!$job instanceof ImportJob || 'completed' === $job->getStatus()) {
            return;
        }

        try {
            $this->imports->run($job);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Escalated\\Import] job %s failed: %s', $jobId, $e->getMessage()));
            $this->em->getConnection()->executeStatement(
                'UPDATE '.$this->em->getClassMetadata(ImportJob::class)->getTableName().' SET status = ? WHERE id = ?',
                ['failed', $jobId],
            );
        }
    }
}
