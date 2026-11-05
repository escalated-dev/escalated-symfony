<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Embed;

use Escalated\Symfony\Service\TwoFactorService;
use Escalated\Symfony\Tests\Kernel\Fixtures\Embed\HostAdminVoter;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * `replace_admin_gate` (ADR 2026-11-05-embeddable-admin): the host's guards
 * alone decide who reaches the embedded admin. Two-factor and route
 * permissions still apply, and nothing outside the embedded admin changes.
 */
final class EmbedReplaceAdminGateTest extends EmbedTestCase
{
    public function testTheHostGuardAloneDecidesWhoReachesTheEmbeddedAdmin(): void
    {
        $this->boot(self::layoutEmbed(['replace_admin_gate' => true]));

        // Admitted by the host, not an Escalated admin: in.
        $this->client->loginUser($this->hostAdmin);
        self::assertSame('Escalated/Admin/Tags/Index', $this->visit('/admin/support/tags')['component']);
        self::assertResponseHeaderSame('X-Host-Guard', 'granted');
        self::assertFalse($this->shared()['is_admin'], 'is_admin keeps Escalated\'s gate');
        self::assertSame(['*'], $this->shared()['permissions'], 'staff on the embedded admin while the gates decide');
        self::assertContains('tags', array_column($this->shared()['navigation'], 'key'), 'the menu follows the host gate');

        // An Escalated admin the host refuses: out.
        $this->client->loginUser($this->escalatedAdmin);
        self::assertSame(403, $this->statusOf('/admin/support/tags'));
        self::assertResponseHeaderSame('X-Host-Guard', 'denied');
    }

    public function testEverythingOutsideTheEmbeddedAdminKeepsEscalatedsGates(): void
    {
        $this->boot(self::layoutEmbed(['replace_admin_gate' => true]));
        $this->client->loginUser($this->hostAdmin);

        self::assertSame(403, $this->statusOf('/support/agent/dashboard'), 'the agent panel keeps the agent gate');
        self::assertSame(403, $this->statusOf('/support/api/plugins/x/y'), 'plugin endpoints keep the admin gate');
    }

    public function testRoutePermissionsStillApply(): void
    {
        $this->boot(self::layoutEmbed(['replace_admin_gate' => true]), ['access' => ['roles_permissions' => true]], seedPermissions: true);
        $this->client->loginUser($this->hostAdmin);

        self::assertSame(403, $this->statusOf('/admin/support/tags'), 'no role, no permission');

        $this->giveAllPermissionsExcept($this->hostAdmin, ['settings.view']);
        self::assertSame(200, $this->statusOf('/admin/support/tags'));
        self::assertSame(403, $this->statusOf('/admin/support/settings'));
        $this->visit('/admin/support/tags');
        self::assertNotContains('settings', array_column($this->shared()['navigation'], 'key'));
    }

    public function testTwoFactorStillApplies(): void
    {
        $this->boot(self::layoutEmbed(['replace_admin_gate' => true]), ['access' => ['two_factor' => true]]);
        $service = static::getContainer()->get(TwoFactorService::class);
        \assert($service instanceof TwoFactorService);
        foreach ([$this->hostAdmin, $this->admin] as $user) {
            $service->begin((int) $user->getId());
            $pending = $service->pendingFor((int) $user->getId());
            self::assertNotNull($pending);
            $service->confirm($pending);
        }

        foreach ([$this->hostAdmin, $this->admin] as $user) {
            $this->client->loginUser($user);
            $this->client->request('GET', '/admin/support/tags');
            self::assertResponseRedirects('/support/two-factor/challenge');
        }
    }

    public function testReplacingTheGateWithoutHostGuardsIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('replace_admin_gate');

        static::bootKernel(['escalated' => [
            'user_class' => TestUser::class,
            'ui' => ['embed' => ['enabled' => true, 'replace_admin_gate' => true]],
        ]]);
    }

    public function testTheGuardsMustBeSecurityAttributes(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        static::bootKernel(['escalated' => [
            'user_class' => TestUser::class,
            'ui' => ['embed' => ['enabled' => true, 'guards' => [HostAdminVoter::ATTRIBUTE, '']]],
        ]]);
    }
}
