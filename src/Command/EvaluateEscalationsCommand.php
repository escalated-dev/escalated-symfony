<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Escalated\Symfony\Service\EscalationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console entry point for the time-based escalation rules engine.
 *
 * Schedule with a cron entry to run every 5 minutes:
 *
 *     cd /var/www/app && bin/console escalated:escalations:run --quiet
 *
 * Distinct from `escalated:automations:run` (general automations) and the
 * event-driven Workflow engine. See escalated-developer-context/
 * domain-model/workflows-automations-macros.md.
 */
#[AsCommand(
    name: 'escalated:escalations:run',
    description: 'Evaluate active escalation rules against open tickets',
)]
class EvaluateEscalationsCommand extends Command
{
    public function __construct(
        private readonly EscalationService $service,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $affected = $this->service->evaluateRules();

        if (0 === $affected) {
            $io->info('No tickets escalated.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Applied escalation actions to %d ticket(s).', $affected));

        return Command::SUCCESS;
    }
}
