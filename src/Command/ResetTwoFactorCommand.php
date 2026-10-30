<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\TwoFactorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Clears a user's two-factor enrolment, for a lost device
 * (escalated-laravel's `escalated:two-factor:reset`).
 */
#[AsCommand(
    name: 'escalated:two-factor:reset',
    description: 'Remove a user\'s two-factor enrolment so they can set it up again',
)]
class ResetTwoFactorCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TwoFactorService $twoFactor,
        #[Autowire('%escalated.user_class%')]
        private readonly string $userClass,
        private readonly ?ManagerRegistry $registry = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('user', InputArgument::REQUIRED, 'The user\'s email address or ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = (string) $input->getArgument('user');

        $userKey = $this->findUserKey($identifier);
        if (null === $userKey) {
            $io->error(sprintf('No user found for [%s].', $identifier));

            return Command::FAILURE;
        }

        $removed = $this->twoFactor->remove($userKey);
        $io->success($removed > 0
            ? sprintf('Removed the two-factor enrolment for %s.', $identifier)
            : sprintf('%s had no two-factor enrolment.', $identifier));

        return Command::SUCCESS;
    }

    private function findUserKey(string $identifier): int|string|null
    {
        $manager = $this->registry?->getManagerForClass($this->userClass) ?? $this->em;
        $repository = $manager->getRepository($this->userClass);
        $metadata = $manager->getClassMetadata($this->userClass);

        $user = $metadata->hasField('email') ? $repository->findOneBy(['email' => $identifier]) : null;
        if (null === $user && preg_match('/^(\d+|[0-9a-f-]{36})$/i', $identifier)) {
            $user = $repository->find(UserKeyResolver::normalize($identifier));
        }
        if (null === $user) {
            return null;
        }

        $ids = $metadata->getIdentifierValues($user);
        $id = reset($ids);

        return false === $id || null === $id ? null : UserKeyResolver::normalize($id);
    }
}
