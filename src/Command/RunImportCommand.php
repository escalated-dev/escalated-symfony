<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Escalated\Symfony\Import\ImportRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs or resumes one import job: what a host's queue worker calls when it
 * takes imports over from the default after-response run.
 */
#[AsCommand(name: 'escalated:import:run', description: 'Run or resume an Escalated import job')]
class RunImportCommand extends Command
{
    public function __construct(private readonly ImportRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::REQUIRED, 'The import job id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->runner->run((string) $input->getArgument('job'));

        return Command::SUCCESS;
    }
}
