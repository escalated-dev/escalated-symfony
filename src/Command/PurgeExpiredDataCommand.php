<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Escalated\Symfony\Service\DataRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * escalated-laravel's `escalated:purge-expired`: applies the data retention
 * settings saved on Admin/Settings/DataRetention. Schedule it daily; with
 * every setting at "never" (the default) it only removes tickets that were
 * soft-deleted more than 30 days ago and expired guest verification codes.
 */
#[AsCommand(
    name: 'escalated:purge-expired',
    description: 'Purge expired data based on the configured retention policies',
)]
class PurgeExpiredDataCommand extends Command
{
    public function __construct(
        private readonly DataRetentionService $retention,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be purged without deleting anything')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Delete closed tickets for good instead of soft-deleting them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        if ($dryRun) {
            $io->note('DRY RUN: no data will be deleted.');
        }

        $result = $this->retention->purge($dryRun, $force);

        $io->writeln(null === $result['tickets']
            ? 'Closed tickets: retention set to never, skipping.'
            : sprintf('Closed tickets: %d past retention%s.', $result['tickets'], $dryRun ? '' : ($force ? ', permanently deleted' : ', soft-deleted (30-day grace period)')));
        $io->writeln(null === $result['attachments']
            ? 'Attachments: retention set to never, skipping.'
            : sprintf('Attachments: %d past retention%s.', $result['attachments'], $dryRun ? '' : ', deleted'));
        $io->writeln(null === $result['audit_logs']
            ? 'Audit logs: retention set to never, skipping.'
            : sprintf('Audit logs: %d past retention%s.', $result['audit_logs'], $dryRun ? '' : ', deleted'));
        $io->writeln(sprintf('Soft-deleted tickets past the %d-day grace period: %d%s.', DataRetentionService::GRACE_DAYS, $result['grace_expired'], $dryRun ? '' : ', permanently deleted'));
        $io->writeln(sprintf('Expired guest verification records: %d%s.', $result['guest_verifications'], $dryRun ? '' : ', deleted'));

        $io->success('Purge complete.');

        return Command::SUCCESS;
    }
}
