<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Access;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Scheduling\SchedulerHeartbeat;
use Escalated\Symfony\Security\HostUserNotConfiguredException;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * A host whose user is not set up the way Escalated needs (a Doctrine entity
 * implementing UserInterface, named by `escalated.user_class`) got a raw
 * Doctrine or type error on the first staff action, or had the user's
 * identifier stored where a user key belongs. Those paths now raise
 * HostUserNotConfiguredException naming the requirement, and
 * `escalated:doctor` reports the setup before anyone clicks.
 */
final class HostUserSetupTest extends EscalatedWebTestCase
{
    public function testAStaffActionByAUserEscalatedCannotKeyIsAClearError(): void
    {
        static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $resolver = static::getContainer()->get(UserKeyResolver::class);
        \assert($resolver instanceof UserKeyResolver);

        try {
            $resolver->require(new InMemoryUser('admin', null, ['ROLE_ESCALATED_ADMIN']));
            self::fail('expected HostUserNotConfiguredException');
        } catch (HostUserNotConfiguredException $e) {
            self::assertStringContainsString(InMemoryUser::class, $e->getMessage());
            self::assertStringContainsString('not a Doctrine entity', $e->getMessage());
            self::assertStringContainsString('Symfony\Component\Security\Core\User\UserInterface', $e->getMessage());
            self::assertStringContainsString('escalated.user_class', $e->getMessage());
        }

        $user = new TestUser('agent@example.com');
        static::entityManager()->persist($user);
        static::entityManager()->flush();
        self::assertSame($user->getId(), $resolver->require($user));
    }

    public function testAUserClassThatIsNotADoctrineUserEntityIsAClearErrorOnTheUsersScreen(): void
    {
        $client = static::clientWithSchema(['escalated' => ['user_class' => 'App\Entity\MissingUser']]);
        $admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        static::entityManager()->persist($admin);
        static::entityManager()->flush();
        $client->loginUser($admin);
        $client->catchExceptions(false);

        $this->expectException(HostUserNotConfiguredException::class);
        $this->expectExceptionMessage('escalated.user_class is "App\Entity\MissingUser", which does not exist.');

        $client->request('GET', '/support/admin/users', server: ['HTTP_X_INERTIA' => 'true']);
    }

    public function testDoctorFailsOnAUserClassEscalatedCannotUse(): void
    {
        static::clientWithSchema(['escalated' => ['user_class' => \stdClass::class]]);

        [$exit, $display] = $this->doctor();

        self::assertSame(1, $exit, $display);
        self::assertStringContainsString('does not implement', $display);
        self::assertStringContainsString('UserInterface', $display);
    }

    public function testDoctorReportsMissingAgentsPermissionsAndRecurringJobs(): void
    {
        static::clientWithSchema(['escalated' => ['user_class' => TestUser::class, 'access' => ['roles_permissions' => true]]]);

        [$exit, $display] = $this->doctor();

        self::assertSame(1, $exit, $display);
        self::assertStringContainsString('no user has an agent profile', $display);
        self::assertStringContainsString('escalated:permissions:seed', $display);
        self::assertStringContainsString('has never run', $display);
        self::assertStringContainsString('escalated:check-sla-breaches', $display);
    }

    public function testDoctorPassesOnAWorkingSetup(): void
    {
        static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $agent = new TestUser('agent@example.com');
        $em = static::entityManager();
        $em->persist($agent);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $agent->getId()));
        $em->flush();
        $heartbeat = static::getContainer()->get(SchedulerHeartbeat::class);
        \assert($heartbeat instanceof SchedulerHeartbeat);
        $heartbeat->record();

        [$exit, $display] = $this->doctor();

        self::assertSame(0, $exit, $display);
        self::assertStringContainsString('Escalated is set up.', $display);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function doctor(): array
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        $console = new ApplicationTester($application);
        $exit = $console->run(['command' => 'escalated:doctor'], ['interactive' => false, 'decorated' => false]);

        return [$exit, preg_replace('/\s+/', ' ', $console->getDisplay()) ?? ''];
    }
}
