<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Escalated\Symfony\Service\SlaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Marks open tickets whose first-response or resolution due time has passed as
 * breached, firing `sla.breached` for each. Schedule it every minute.
 */
#[AsCommand(
    name: 'escalated:check-sla-breaches',
    description: 'Mark tickets past their SLA due times as breached and fire sla.breached',
)]
class CheckSlaBreachesCommand extends Command
{
    public function __construct(
        private readonly SlaService $slaService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $breached = $this->slaService->checkBreaches();

        if (0 === $breached) {
            $io->info('No new SLA breaches.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Marked %d new SLA breach(es).', $breached));

        return Command::SUCCESS;
    }
}
