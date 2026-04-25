<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Escalated\Symfony\Service\AutomationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console entry point for the time-based admin Automation engine.
 *
 * Schedule with a cron entry to run every 5 minutes (`*` slash 5 in the
 * minute field, omitted from this docblock to avoid breaking the comment):
 *
 *     cd /var/www/app && bin/console escalated:automations:run --quiet
 *
 * See escalated-developer-context/domain-model/workflows-automations-macros.md
 * for the canonical taxonomy that distinguishes Automations (this command's
 * scope) from Workflows (event-driven, no cron) and Macros (agent-applied).
 */
#[AsCommand(
    name: 'escalated:automations:run',
    description: 'Run all active time-based admin automations against open tickets',
)]
class RunAutomationsCommand extends Command
{
    public function __construct(
        private readonly AutomationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $affected = $this->runner->run();

        if (0 === $affected) {
            $io->info('No tickets matched.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Applied actions to %d ticket(s).', $affected));

        return Command::SUCCESS;
    }
}
