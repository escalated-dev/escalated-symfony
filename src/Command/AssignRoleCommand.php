<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Entity\Role;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Security\UserPermissions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gives a user an Escalated role, so an install can bootstrap its first admin
 * before turning roles & permissions on (escalated-laravel's
 * `escalated:roles:assign`).
 */
#[AsCommand(
    name: 'escalated:roles:assign',
    description: 'Give a user an Escalated role',
)]
class AssignRoleCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPermissions $permissions,
        #[Autowire('%escalated.user_class%')]
        private readonly string $userClass,
        private readonly ?ManagerRegistry $registry = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::REQUIRED, 'The user\'s email address or ID')
            ->addArgument('role', InputArgument::OPTIONAL, 'The role slug (admin, agent, light_agent or a custom role)', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = (string) $input->getArgument('user');
        $slug = (string) $input->getArgument('role');

        $userKey = $this->findUserKey($identifier);
        if (null === $userKey) {
            $io->error(sprintf('No user found for [%s].', $identifier));

            return Command::FAILURE;
        }

        $role = $this->em->getRepository(Role::class)->findOneBy(['slug' => $slug]);
        if (null === $role) {
            $io->error(sprintf('No role [%s]. Seed them with: bin/console escalated:permissions:seed', $slug));

            return Command::FAILURE;
        }

        $this->permissions->assign($userKey, $role);
        $io->success(sprintf('Gave %s the %s role.', $identifier, $role->getSlug()));

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
