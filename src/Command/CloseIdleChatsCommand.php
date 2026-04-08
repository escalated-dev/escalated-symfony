<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Escalated\Symfony\Service\ChatSessionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'escalated:chat:close-idle',
    description: 'Close chat sessions that have been idle beyond the configured threshold',
)]
class CloseIdleChatsCommand extends Command
{
    public function __construct(
        private readonly ChatSessionService $chatSessionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('idle-minutes', null, InputOption::VALUE_REQUIRED, 'Minutes of inactivity before closing', '30')
            ->addOption('abandon-minutes', null, InputOption::VALUE_REQUIRED, 'Minutes waiting without agent before marking abandoned', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $idleMinutes = (int) $input->getOption('idle-minutes');
        $abandonMinutes = (int) $input->getOption('abandon-minutes');

        $idleClosed = $this->chatSessionService->closeIdleSessions($idleMinutes);
        $abandoned = $this->chatSessionService->markAbandonedSessions($abandonMinutes);

        $output->writeln(sprintf('Closed %d idle session(s).', $idleClosed));
        $output->writeln(sprintf('Marked %d session(s) as abandoned.', $abandoned));

        return Command::SUCCESS;
    }
}
