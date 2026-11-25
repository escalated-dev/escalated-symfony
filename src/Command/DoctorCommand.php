<?php

declare(strict_types=1);

namespace Escalated\Symfony\Command;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Permission;
use Escalated\Symfony\Entity\RoleUser;
use Escalated\Symfony\Scheduling\SchedulerHeartbeat;
use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\EnsureAdminVoter;
use Escalated\Symfony\Security\HostUserSetup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks the setup a working install needs and says what to fix:
 *
 *  - the host user (`escalated.user_class`): an existing class implementing
 *    UserInterface and mapped by Doctrine, since Escalated stores its key;
 *  - who the gates let in: ESCALATED_AGENT needs an agent profile, and with
 *    roles & permissions on, the permissions must be seeded and roles given;
 *  - the recurring jobs: whether escalated:check-sla-breaches ran in the last
 *    15 minutes, and the commands to schedule when it has not.
 *
 * Fails (exit 1) on a problem that breaks staff actions; warnings alone pass.
 */
#[AsCommand(
    name: 'escalated:doctor',
    description: 'Check the Escalated setup: host user entity, gates and permissions, and the recurring jobs',
)]
class DoctorCommand extends Command
{
    public function __construct(
        private readonly HostUserSetup $hostUsers,
        private readonly EntityManagerInterface $em,
        private readonly SchedulerHeartbeat $heartbeat,
        private readonly AccessFeatures $features = new AccessFeatures(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = [];
        $warnings = [];

        $problem = $this->hostUsers->userClassProblem();
        if (null === $problem) {
            $io->writeln(sprintf('<info>OK</info>   Host user: %s is a Doctrine entity implementing UserInterface.', $this->hostUsers->userClass()));
        } else {
            $errors[] = sprintf(
                'Host user: escalated.user_class is "%s", which %s. Set it to the Doctrine user entity your firewall signs in; '
                .'it must implement Symfony\Component\Security\Core\User\UserInterface. Staff actions fail until it does.',
                $this->hostUsers->userClass(),
                $problem,
            );
        }

        $io->writeln(sprintf(
            '<info>OK</info>   Admin gate: ESCALATED_ADMIN is granted to users with the %s role; give it to your admins in your user provider.',
            EnsureAdminVoter::ROLE,
        ));

        $agents = $this->count(AgentProfile::class);
        if (null === $agents) {
            $errors[] = 'Database: the Escalated tables are missing. Run "php bin/console doctrine:migrations:migrate".';
        } elseif (0 === $agents) {
            $warnings[] = 'Agent gate: no user has an agent profile, so ESCALATED_AGENT grants no one the agent screens. '
                .'Create an AgentProfile for each agent\'s user key (docs/getting-started.md, "Set up security").';
        } else {
            $io->writeln(sprintf('<info>OK</info>   Agent gate: %d user(s) have an agent profile.', $agents));
        }

        if ($this->features->rolesPermissions() && null !== $agents) {
            if (0 === $this->count(Permission::class)) {
                $errors[] = 'Permissions: roles & permissions are on but no permission is seeded, so every staff route is refused. '
                    .'Run "php bin/console escalated:permissions:seed".';
            } elseif (0 === $this->count(RoleUser::class)) {
                $errors[] = 'Permissions: roles & permissions are on but no user has an Escalated role, so every staff route is refused. '
                    .'Run "php bin/console escalated:roles:assign you@example.com admin".';
            } else {
                $io->writeln('<info>OK</info>   Permissions: seeded, and roles are assigned.');
            }
        }

        $scheduler = null === $agents ? null : $this->heartbeat->status();
        if (null !== $scheduler && $scheduler['healthy']) {
            $io->writeln(sprintf('<info>OK</info>   Recurring jobs: %s last ran at %s.', 'escalated:check-sla-breaches', (string) $scheduler['last_run_at']));
        } elseif (null !== $scheduler) {
            $lines = array_map(static fn (array $c): string => sprintf('  %s  (%s)', $c['command'], $c['interval']), $scheduler['commands']);
            $warnings[] = 'Recurring jobs: '.$scheduler['message']."\n".implode("\n", $lines);
        }

        foreach ($warnings as $warning) {
            $io->warning($warning);
        }
        foreach ($errors as $error) {
            $io->error($error);
        }

        if ([] !== $errors) {
            return Command::FAILURE;
        }

        $io->success([] === $warnings ? 'Escalated is set up.' : 'Escalated is set up, with the warnings above.');

        return Command::SUCCESS;
    }

    /**
     * @param class-string $entity
     */
    private function count(string $entity): ?int
    {
        try {
            return (int) $this->em->createQueryBuilder()->select('COUNT(e)')->from($entity, 'e')->getQuery()->getSingleScalarResult();
        } catch (\Throwable) {
            return null;
        }
    }
}
