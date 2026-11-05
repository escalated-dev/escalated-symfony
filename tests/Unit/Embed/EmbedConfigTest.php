<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Unit\Embed;

use Escalated\Symfony\Embed\EmbedConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class EmbedConfigTest extends TestCase
{
    public function testEverythingIsOffByDefault(): void
    {
        $config = new EmbedConfig();

        self::assertFalse($config->enabled());
        self::assertSame('layout', $config->mode());
        self::assertSame('admin/support', $config->prefix());
        self::assertSame([], $config->guards());
        self::assertFalse($config->replacesAdminGate());
        self::assertNull($config->layout());
        self::assertNull($config->rootView());
        self::assertSame([], $config->frameAncestors());
        self::assertFalse($config->isEmbeddedRequest(self::embeddedRequest()), 'nothing is embedded while it is off');
    }

    public function testItNeedsTheUi(): void
    {
        self::assertTrue((new EmbedConfig(enabled: true))->enabled());
        self::assertFalse((new EmbedConfig(enabled: true, uiEnabled: false))->enabled());
        self::assertTrue((new EmbedConfig(enabled: 'true'))->enabled(), 'env strings');
        self::assertFalse((new EmbedConfig(enabled: 'no'))->enabled());
    }

    public function testValuesAreNormalised(): void
    {
        $config = new EmbedConfig(
            enabled: true,
            mode: 'carousel',
            prefix: '//',
            layout: '',
            rootView: '',
            frameAncestors: ' https://a.example , *, https://*.b.example, https://c.example/x, ftp://d.example, https://a.example, https://E.example:8443/ ',
        );

        self::assertSame('layout', $config->mode(), 'an unknown mode is layout');
        self::assertSame('admin/support', $config->prefix(), 'an empty prefix is the default');
        self::assertNull($config->layout());
        self::assertNull($config->rootView());
        self::assertSame(['https://a.example', 'https://e.example:8443'], $config->frameAncestors());
        self::assertSame("frame-ancestors 'self' https://a.example https://e.example:8443", $config->frameAncestorsDirective());
        self::assertSame("frame-ancestors 'self'", (new EmbedConfig(enabled: true, mode: 'iframe'))->frameAncestorsDirective());

        self::assertSame('backoffice/help', (new EmbedConfig(prefix: '/backoffice/help/'))->prefix());
        self::assertSame('iframe', (new EmbedConfig(mode: 'iframe'))->mode());
    }

    public function testTheSharedPropOnEmbeddedAndOtherRequests(): void
    {
        $config = new EmbedConfig(enabled: true, mode: 'iframe', prefix: 'admin/help', layout: 'Layouts/Admin', frameAncestors: ['https://a.example']);

        self::assertSame([
            'enabled' => true,
            'mode' => 'iframe',
            'layout' => 'Layouts/Admin',
            'route' => 'escalated.admin.tags.index',
            'base_url' => '/admin/help',
            'allowed_origins' => ['https://a.example'],
        ], $config->shared(self::embeddedRequest()));

        $other = Request::create('/support/agent');
        $other->attributes->set('_route', 'escalated.agent.dashboard');
        self::assertSame([
            'enabled' => false,
            'mode' => 'iframe',
            'layout' => 'Layouts/Admin',
            'route' => null,
            'base_url' => '/admin/help',
            'allowed_origins' => ['https://a.example'],
        ], $config->shared($other));

        self::assertSame([], (new EmbedConfig(enabled: true, frameAncestors: ['https://a.example']))->shared($other)['allowed_origins'], 'layout mode lists no origins');
    }

    public function testEmbedAdminBuildsTheBundleConfiguration(): void
    {
        self::assertSame(['ui' => ['embed' => [
            'enabled' => true,
            'mode' => 'layout',
            'prefix' => 'admin/support',
            'guards' => ['ROLE_ADMIN'],
            'replace_admin_gate' => false,
            'layout' => 'Layouts/AdminLayout',
            'root_view' => null,
            'frame_ancestors' => [],
        ]]], EmbedConfig::embedAdmin(guards: ['ROLE_ADMIN'], layout: 'Layouts/AdminLayout'));

        $this->expectException(\InvalidArgumentException::class);
        EmbedConfig::embedAdmin(mode: 'popup');
    }

    private static function embeddedRequest(): Request
    {
        $request = Request::create('/admin/help/tags');
        $request->attributes->set('_route', 'escalated.admin.tags.index');
        $request->attributes->set(EmbedConfig::REQUEST_ATTRIBUTE, true);

        return $request;
    }
}
