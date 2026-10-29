<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Escalated\Symfony\Plugin\PluginRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the cron schedules of active SDK plugins that are due this minute
 * (UTC), through the plugin runtime. Schedule it every minute.
 */
#[AsCommand(
    name: 'escalated:plugins:cron',
    description: 'Run the due cron schedules of active SDK plugins',
)]
class RunPluginCronCommand extends Command
{
    public function __construct(
        private readonly PluginRegistry $plugins,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('at', null, InputOption::VALUE_REQUIRED, 'The minute to run, as an ISO 8601 time (default: now)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $at = $input->getOption('at');
        try {
            $minute = \is_string($at) ? new \DateTimeImmutable($at) : new \DateTimeImmutable();
        } catch (\Exception) {
            $io->error('--at must be a time, such as 2026-10-29T09:00:00Z.');

            return Command::INVALID;
        }

        $ran = $this->plugins->runDueCron($minute);
        $io->info(sprintf('Ran %d plugin cron schedule(s).', $ran));

        return Command::SUCCESS;
    }
}
