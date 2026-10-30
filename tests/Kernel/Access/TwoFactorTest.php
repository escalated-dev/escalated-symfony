<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Access;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\TwoFactor;
use Escalated\Symfony\Security\PermissionSeeder;
use Escalated\Symfony\Security\TwoFactorSession;
use Escalated\Symfony\Service\ApiTokenService;
use Escalated\Symfony\Service\SettingsService;
use Escalated\Symfony\Service\TwoFactorService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Two-factor authentication behind the install-level switch
 * (ADR 2026-10-29-configurable-access-features, "Port test checklist":
 * Switches and Two-factor), through the real HTTP stack.
 */
final class TwoFactorTest extends EscalatedWebTestCase
{
    private const CHALLENGE = '/support/two-factor/challenge';
    private const ENROL = '/support/admin/settings/two-factor';
    private const DASHBOARD = '/support/agent/dashboard';

    private KernelBrowser $client;
    private TestUser $admin;
    private TestUser $agent;

    /** @var list<string> the recovery codes of the last enrol() */
    private array $codes = [];

    // ---- Switch off (the default) -----------------------------------------

    public function testWithTheSwitchOffNobodyIsChallengedAndTheRoutesAnswer404(): void
    {
        $this->boot(['access' => []]);
        $this->enrol($this->agent);
        $this->client->loginUser($this->agent);

        $this->visit(self::DASHBOARD);
        $shared = $this->shared();
        self::assertFalse($shared['features']['two_factor']);
        self::assertFalse($shared['two_factor_required']);

        foreach ([['GET', self::ENROL], ['POST', self::ENROL.'/setup'], ['POST', self::ENROL.'/confirm'], ['POST', self::ENROL.'/disable'], ['GET', self::CHALLENGE], ['POST', self::CHALLENGE]] as [$method, $uri]) {
            $this->client->request($method, $uri, ['code' => '000000']);
            self::assertResponseStatusCodeSame(404, $method.' '.$uri);
        }
    }

    public function testTheRequirementIsIgnoredWhileTheSwitchIsOff(): void
    {
        $this->boot(['access' => [], 'two_factor' => ['required' => true]]);
        $this->client->loginUser($this->agent);

        $this->visit(self::DASHBOARD);
        self::assertFalse($this->shared()['two_factor_required']);
    }

    public function testTheSwitchIsNotASetting(): void
    {
        $this->boot(['access' => []]);
        $this->client->loginUser($this->admin);

        $this->client->request('POST', '/support/admin/settings', ['two_factor' => '1', 'access.two_factor' => '1', 'ESCALATED_TWO_FACTOR' => 'true']);
        self::assertLessThan(500, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', self::ENROL);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheSwitchReadsTheEnvVarWhenNotConfigured(): void
    {
        $_SERVER['ESCALATED_TWO_FACTOR'] = $_ENV['ESCALATED_TWO_FACTOR'] = 'true';
        putenv('ESCALATED_TWO_FACTOR=true');
        try {
            $this->boot(['access' => []]);
            $this->client->loginUser($this->agent);

            $page = $this->visit(self::ENROL);
            self::assertSame('Escalated/Admin/Settings/TwoFactor', $page['component']);
            self::assertTrue($this->shared()['features']['two_factor']);
        } finally {
            unset($_SERVER['ESCALATED_TWO_FACTOR'], $_ENV['ESCALATED_TWO_FACTOR']);
            putenv('ESCALATED_TWO_FACTOR');
        }
    }

    // ---- Challenge (switch on) ---------------------------------------------

    public function testAnEnrolledUserIsSentToTheChallengeFromAdminAndAgentPages(): void
    {
        $this->boot();
        $this->enrol($this->admin);
        $this->client->loginUser($this->admin);

        $this->client->request('GET', self::DASHBOARD);
        self::assertResponseRedirects(self::CHALLENGE);
        $this->client->request('GET', '/support/admin/departments');
        self::assertResponseRedirects(self::CHALLENGE);
        $this->inertia('GET', '/support/admin/tags');
        self::assertResponseRedirects(self::CHALLENGE);

        $this->json('GET', '/support/agent/tickets');
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->body()['two_factor_required']);
    }

    public function testTheChallengeRendersTheSharedPageWithItsAction(): void
    {
        $this->boot();
        $this->enrol($this->agent);
        $this->client->loginUser($this->agent);

        $page = $this->visit(self::CHALLENGE);
        self::assertSame('Escalated/Auth/TwoFactorChallenge', $page['component']);
        self::assertSame('http://localhost/support/two-factor/challenge', $page['props']['action']);
        self::assertSame('', $page['props']['error']);
        self::assertTrue($this->shared()['features']['two_factor']);
    }

    public function testAValidCodePassesAndReturnsToTheIntendedPage(): void
    {
        $this->boot();
        $secret = $this->enrol($this->agent);
        $this->client->loginUser($this->agent);

        $this->client->request('GET', '/support/agent/tickets?status=open');
        self::assertResponseRedirects(self::CHALLENGE);
        $sessionBefore = $this->sessionId();

        $this->client->request('POST', self::CHALLENGE, ['code' => $this->code($secret)]);
        self::assertResponseRedirects('/support/agent/tickets?status=open');
        self::assertNotSame($sessionBefore, $this->sessionId(), 'passing the challenge regenerates the session ID');

        $this->visit('/support/agent/tickets?status=open');
        $this->visit('/support/admin/settings/two-factor');
    }

    public function testAWrongCodeIsAnErrorOnCode(): void
    {
        $this->boot();
        $secret = $this->enrol($this->agent);
        $this->client->loginUser($this->agent);
        $wrong = str_pad((string) (((int) $this->code($secret) + 1) % 1000000), 6, '0', \STR_PAD_LEFT);

        $this->client->request('POST', self::CHALLENGE, ['code' => $wrong]);
        self::assertResponseRedirects(self::CHALLENGE);
        self::assertSame(['code' => 'That code is not valid.'], $this->flash('errors')[0] ?? null);

        $this->json('POST', self::CHALLENGE, ['code' => $wrong]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('code', $this->body()['errors']);

        $this->client->request('GET', self::DASHBOARD);
        self::assertResponseRedirects(self::CHALLENGE);
    }

    public function testACodeCannotBeUsedTwice(): void
    {
        $this->boot();
        $secret = $this->enrol($this->agent);
        $this->client->loginUser($this->agent);
        $code = $this->code($secret);

        $this->client->request('POST', self::CHALLENGE, ['code' => $code]);
        self::assertResponseRedirects(self::DASHBOARD);

        $this->signIn($this->agent);
        $this->client->request('POST', self::CHALLENGE, ['code' => $code]);
        self::assertResponseRedirects(self::CHALLENGE);
        self::assertSame(['code' => 'That code is not valid.'], $this->flash('errors')[0] ?? null);
    }

    public function testARecoveryCodeWorksOnce(): void
    {
        $this->boot();
        $this->enrol($this->agent);
        [$used] = $this->codes;
        $this->client->loginUser($this->agent);

        $this->client->request('POST', self::CHALLENGE, ['recovery_code' => strtolower($used)]);
        self::assertResponseRedirects(self::DASHBOARD);
        $this->visit(self::DASHBOARD);

        $this->signIn($this->agent);
        $this->client->request('POST', self::CHALLENGE, ['recovery_code' => $used]);
        self::assertResponseRedirects(self::CHALLENGE);
        self::assertSame(['recovery_code' => 'That recovery code is not valid.'], $this->flash('errors')[0] ?? null);

        static::entityManager()->clear();
        self::assertSame(\array_slice($this->codes, 1), $this->service()->recoveryCodesOf($this->enrolment($this->agent)));
    }

    public function testTheVerifyRouteIsThrottledPerUser(): void
    {
        $this->boot();
        $secret = $this->enrol($this->agent);
        $this->client->loginUser($this->agent);

        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('POST', self::CHALLENGE, ['code' => '000000']);
            self::assertResponseRedirects(self::CHALLENGE);
        }

        $this->client->request('POST', self::CHALLENGE, ['code' => $this->code($secret)]);
        self::assertResponseStatusCodeSame(429);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));

        // Another user has their own budget.
        $this->enrol($this->admin);
        $this->client->loginUser($this->admin);
        $this->client->request('POST', self::CHALLENGE, ['code' => '000000']);
        self::assertResponseRedirects(self::CHALLENGE);
    }

    public function testANewLoginIsChallengedAgainAndLogoutClearsThePass(): void
    {
        $this->boot();
        $secret = $this->enrol($this->agent);
        $this->client->loginUser($this->agent);

        $this->client->request('POST', self::CHALLENGE, ['code' => $this->code($secret)]);
        $this->visit(self::DASHBOARD);

        // The host signs the user in again: the session (and its attributes)
        // is migrated, but the pass is not carried over.
        $this->signIn($this->agent);
        $this->client->request('GET', self::DASHBOARD);
        self::assertResponseRedirects(self::CHALLENGE);

        $this->client->request('POST', self::CHALLENGE, ['recovery_code' => $this->codes[0]]);
        $this->visit(self::DASHBOARD);
        $this->client->request('GET', '/test-logout');
        self::assertNull($this->client->getRequest()->getSession()->get(TwoFactorSession::KEY), 'logout clears the pass');
    }

    public function testAnUnchallengedSessionCannotSwitchTwoFactorOff(): void
    {
        $this->boot();
        $this->enrol($this->agent);
        $this->client->loginUser($this->agent);

        $this->client->request('POST', self::ENROL.'/disable');
        self::assertResponseRedirects(self::CHALLENGE);
        self::assertNotNull($this->enrolment($this->agent));
    }

    public function testThePassBelongsToOneUser(): void
    {
        $this->boot();
        $this->enrol($this->agent);
        $this->client->loginUser($this->agent);
        $session = new Session(new MockArraySessionStorage());
        TwoFactorSession::markPassed($session, (int) $this->admin->getId());

        self::assertFalse(TwoFactorSession::passed($session, (int) $this->agent->getId()));
        self::assertTrue(TwoFactorSession::passed($session, (int) $this->admin->getId()));
    }

    public function testTheTokenApiIsNotChallenged(): void
    {
        $this->boot();
        $this->enrol($this->admin);
        $tokens = static::getContainer()->get(ApiTokenService::class);
        \assert($tokens instanceof ApiTokenService);
        $token = $tokens->createToken((int) $this->admin->getId(), 'ci', ['*'])['plainTextToken'];

        $this->client->request('GET', '/support/api/v1/auth/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/support/api/v1/tickets', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
    }

    // ---- Enrolment ---------------------------------------------------------

    public function testAnAgentWhoIsNotAnAdminCanEnrol(): void
    {
        // Personal: no permission needed even with roles & permissions on.
        $this->boot(['access' => ['two_factor' => true, 'roles_permissions' => true]]);
        $this->client->loginUser($this->agent);

        $page = $this->visit(self::ENROL);
        self::assertSame('Escalated/Admin/Settings/TwoFactor', $page['component']);
        self::assertSame(['enabled' => false, 'pending' => false, 'required' => false], $page['props']);

        $this->client->request('POST', self::ENROL.'/setup');
        self::assertResponseRedirects(self::ENROL);
        $setup = $this->flash('two_factor_setup')[0] ?? null;
        self::assertIsArray($setup);
        self::assertStringStartsWith('otpauth://totp/', $setup['qr_uri']);
        self::assertCount(8, $setup['recovery_codes']);
        parse_str((string) parse_url($setup['qr_uri'], \PHP_URL_QUERY), $query);
        $secret = (string) $query['secret'];
        self::assertSame(32, \strlen($secret));

        // Stored sealed, never in plain text.
        $row = static::entityManager()->getConnection()->fetchAssociative('SELECT secret, recovery_codes FROM escalated_two_factors');
        self::assertIsArray($row);
        self::assertStringNotContainsString($secret, (string) $row['secret']);
        foreach ($setup['recovery_codes'] as $recoveryCode) {
            self::assertStringNotContainsString($recoveryCode, (string) $row['recovery_codes']);
        }
        self::assertSame(['enabled' => false, 'pending' => true, 'required' => false], $this->visit(self::ENROL)['props']);

        $this->client->request('POST', self::ENROL.'/confirm', ['code' => '12345']);
        self::assertSame(['code' => 'The code must be the six digits your authenticator app shows.'], $this->flash('errors')[0] ?? null);

        $this->client->request('POST', self::ENROL.'/confirm', ['code' => $this->code($secret)]);
        self::assertResponseRedirects(self::ENROL);
        self::assertSame(['recovery_codes' => $setup['recovery_codes']], $this->flash('two_factor_confirmed')[0] ?? null);

        // Confirming proves the second factor for this session.
        self::assertTrue($this->visit(self::ENROL)['props']['enabled']);
        $this->signIn($this->agent);
        $this->client->request('GET', self::ENROL);
        self::assertResponseRedirects(self::CHALLENGE);
    }

    public function testDisablingRemovesTheEnrolment(): void
    {
        $this->boot();
        $secret = $this->enrol($this->agent);
        $this->client->loginUser($this->agent);
        $this->client->request('POST', self::CHALLENGE, ['code' => $this->code($secret)]);

        $this->client->request('POST', self::ENROL.'/disable');
        self::assertResponseRedirects(self::ENROL);
        static::entityManager()->clear();
        self::assertNull($this->enrolment($this->agent));
    }

    public function testCustomersCannotEnrol(): void
    {
        $this->boot();
        $customer = new TestUser('customer@example.com');
        static::entityManager()->persist($customer);
        static::entityManager()->flush();
        $this->client->loginUser($customer);

        $this->client->request('GET', self::ENROL);
        self::assertResponseStatusCodeSame(403);
    }

    // ---- Required ----------------------------------------------------------

    public function testWhenRequiredStaffWithoutAnEnrolmentMustEnrolFirst(): void
    {
        $this->boot(['access' => ['two_factor' => true], 'two_factor' => ['required' => true]]);
        $this->client->loginUser($this->agent);

        $this->client->request('GET', self::DASHBOARD);
        self::assertResponseRedirects(self::ENROL);
        $this->client->request('GET', '/support/admin/departments');
        self::assertResponseRedirects(self::ENROL);
        $this->json('GET', '/support/agent/tickets');
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->body()['two_factor_required']);

        $page = $this->visit(self::ENROL);
        self::assertTrue($page['props']['required']);
        self::assertTrue($this->shared()['two_factor_required']);
        $this->client->request('POST', self::ENROL.'/setup');
        self::assertNotEmpty($this->flash('two_factor_setup'));
    }

    public function testWhenNotRequiredStaffWithoutAnEnrolmentGetIn(): void
    {
        $this->boot();
        $this->client->loginUser($this->agent);

        $this->visit(self::DASHBOARD);
        self::assertFalse($this->shared()['two_factor_required']);
    }

    public function testTheSettingWinsOverTheConfig(): void
    {
        $this->boot();
        $this->client->loginUser($this->admin);
        $this->visit(self::DASHBOARD);

        $this->settings()->set('two_factor_required', '1');
        $this->client->request('GET', self::DASHBOARD);
        self::assertResponseRedirects(self::ENROL);
    }

    public function testASavedSettingTurnsTheConfiguredRequirementOff(): void
    {
        $this->boot(['access' => ['two_factor' => true], 'two_factor' => ['required' => true]]);
        $this->settings()->set('two_factor_required', '0');
        $this->client->loginUser($this->admin);
        $this->visit(self::DASHBOARD);
    }

    public function testAnAdminSavesTheRequirementFromTheTwoFactorPage(): void
    {
        $this->boot();
        $this->client->loginUser($this->admin);

        $this->inertia('POST', '/support/admin/settings', ['two_factor_required' => true]);
        self::assertResponseRedirects(self::ENROL);
        self::assertTrue($this->settings()->getBool('two_factor_required'));
        self::assertTrue($this->visit(self::ENROL)['props']['required']);

        // Once required, the admin must enrol before reaching any other screen.
        $this->inertia('POST', '/support/admin/settings', ['two_factor_required' => false]);
        self::assertResponseRedirects(self::ENROL);
        self::assertTrue($this->settings()->getBool('two_factor_required'));

        $secret = $this->enrol($this->admin);
        $this->client->request('POST', self::CHALLENGE, ['code' => $this->code($secret)]);
        $this->inertia('POST', '/support/admin/settings', ['two_factor_required' => false]);
        self::assertResponseRedirects(self::ENROL);
        self::assertFalse($this->settings()->getBool('two_factor_required', true));
    }

    // ---- Reset -------------------------------------------------------------

    public function testTheResetCommandRemovesAnEnrolment(): void
    {
        $this->boot();
        $this->enrol($this->agent);
        $reset = new CommandTester((new Application(static::$kernel))->find('escalated:two-factor:reset'));

        self::assertSame(0, $reset->execute(['user' => 'agent@example.com']));
        static::entityManager()->clear();
        self::assertNull($this->enrolment($this->agent));
        self::assertSame(0, $reset->execute(['user' => (string) $this->agent->getId()]), 'no enrolment is not an error');
        self::assertSame(1, $reset->execute(['user' => 'nobody@example.com']));
    }

    // ---- Helpers -------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config = ['access' => ['two_factor' => true]]): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class, ...$config]]);

        $em = static::entityManager();
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $this->agent = new TestUser('agent@example.com');
        $em->persist($this->admin);
        $em->persist($this->agent);
        $em->flush();
        foreach ([$this->admin, $this->agent] as $user) {
            $em->persist((new AgentProfile())->setUserId((int) $user->getId()));
        }
        $em->flush();

        $seeder = static::getContainer()->get(PermissionSeeder::class);
        \assert($seeder instanceof PermissionSeeder);
        $seeder->seed();
    }

    /**
     * A confirmed enrolment made the way the enrolment screen makes one; the
     * secret, with the recovery codes in $this->codes.
     */
    private function enrol(TestUser $user): string
    {
        $service = $this->service();
        $enrolment = $service->begin((int) $user->getId());
        $twoFactor = $service->pendingFor((int) $user->getId());
        self::assertNotNull($twoFactor);
        $service->confirm($twoFactor);
        $this->codes = $enrolment['recovery_codes'];

        return $enrolment['secret'];
    }

    private function enrolment(TestUser $user): ?TwoFactor
    {
        return static::entityManager()->getRepository(TwoFactor::class)->findOneBy(['userId' => $user->getId()]);
    }

    private function code(string $secret): string
    {
        $totp = new Google2FA();

        return $totp->oathTotp($secret, $totp->getTimestamp());
    }

    /** Sign in again through the host firewall (dispatches LoginSuccessEvent). */
    private function signIn(TestUser $user): void
    {
        $this->client->request('POST', '/test-login', ['email' => $user->getEmail()]);
        self::assertResponseIsSuccessful();
    }

    private function service(): TwoFactorService
    {
        $service = static::getContainer()->get(TwoFactorService::class);
        \assert($service instanceof TwoFactorService);

        return $service;
    }

    private function settings(): SettingsService
    {
        $settings = static::getContainer()->get(SettingsService::class);
        \assert($settings instanceof SettingsService);

        return $settings;
    }

    /** The ID of the session the last request ended with. */
    private function sessionId(): string
    {
        return $this->client->getRequest()->getSession()->getId();
    }

    /**
     * @return list<mixed>
     */
    private function flash(string $type): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peek($type);
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(string $uri): array
    {
        $this->inertia('GET', $uri);
        self::assertResponseIsSuccessful($uri);
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function inertia(string $method, string $uri, array $body = []): void
    {
        $this->client->request($method, $uri, server: [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'CONTENT_TYPE' => 'application/json',
        ], content: 'GET' === $method ? null : (string) json_encode($body));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $uri, array $body = []): void
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: 'GET' === $method ? null : (string) json_encode($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(): array
    {
        $shared = $this->client->getRequest()->attributes->get('_escalated_shared');
        self::assertIsArray($shared);

        return $shared;
    }
}
