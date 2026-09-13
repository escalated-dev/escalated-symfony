<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Escalated\Symfony\Service\WorkflowEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs workflow actions that were scheduled with a delay and are now due.
 * Schedule it every minute.
 */
#[AsCommand(
    name: 'escalated:process-delayed-actions',
    description: 'Run delayed workflow actions that are due',
)]
class ProcessDelayedActionsCommand extends Command
{
    public function __construct(
        private readonly WorkflowEngine $workflowEngine,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->workflowEngine->processDelayedActions();

        $io->success('Processed due delayed workflow actions.');

        return Command::SUCCESS;
    }
}
