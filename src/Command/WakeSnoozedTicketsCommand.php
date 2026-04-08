<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Escalated\Symfony\Service\SnoozeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'escalated:wake-snoozed-tickets',
    description: 'Wake up tickets whose snooze period has expired',
)]
class WakeSnoozedTicketsCommand extends Command
{
    public function __construct(
        private readonly SnoozeService $snoozeService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $woken = $this->snoozeService->wakeExpiredTickets();

        if (empty($woken)) {
            $io->info('No snoozed tickets to wake.');

            return Command::SUCCESS;
        }

        foreach ($woken as $ticket) {
            $io->writeln(sprintf(
                'Woke ticket %s (restored to %s)',
                $ticket->getReference(),
                $ticket->getStatus(),
            ));
        }

        $io->success(sprintf('Woke %d ticket(s).', count($woken)));

        return Command::SUCCESS;
    }
}
