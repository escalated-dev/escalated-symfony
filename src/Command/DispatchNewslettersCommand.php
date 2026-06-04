<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Newsletter\Newsletter;
use Escalated\Symfony\Service\Newsletter\NewsletterDispatcher;
use Escalated\Symfony\Service\Newsletter\NewsletterPlanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'escalated:newsletters:dispatch',
    description: 'Plan scheduled newsletters whose time has come and dispatch a batch of pending deliveries.',
)]
class DispatchNewslettersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsletterPlanner $planner,
        private readonly NewsletterDispatcher $dispatcher,
        private readonly bool $enabled = false,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->enabled) {
            $io->info('Newsletter feature disabled — skipping.');

            return Command::SUCCESS;
        }

        $due = $this->em->createQueryBuilder()
            ->select('n')
            ->from(Newsletter::class, 'n')
            ->where('n.status = :status')
            ->andWhere('n.scheduledAt <= :now')
            ->setParameter('status', 'scheduled')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();

        foreach ($due as $newsletter) {
            if ($newsletter instanceof Newsletter) {
                $io->writeln(sprintf('Planning newsletter #%d', $newsletter->getId()));
                $this->planner->plan($newsletter);
            }
        }

        $io->writeln('Dispatching batch...');
        $this->dispatcher->dispatchBatch();
        $io->success('Done.');

        return Command::SUCCESS;
    }
}
