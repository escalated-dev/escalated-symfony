<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Escalated\Symfony\Security\PermissionSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * escalated-laravel's PermissionSeeder as a command: seeds every permission
 * and the system roles admin, agent and light_agent. Safe to run again.
 */
#[AsCommand(
    name: 'escalated:permissions:seed',
    description: 'Seed the Escalated permissions and the system roles (admin, agent, light_agent)',
)]
class SeedPermissionsCommand extends Command
{
    public function __construct(private readonly PermissionSeeder $seeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->seeder->seed();

        $io->writeln(sprintf('Seeded %d permissions.', $result['permissions']));
        foreach ($result['roles'] as $slug => $count) {
            $io->writeln(sprintf('Role "%s" synced with %d permissions.', $slug, $count));
        }

        return Command::SUCCESS;
    }
}
