<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Embed;

use Escalated\Symfony\Tests\Kernel\Fixtures\Embed\HostHeadersSubscriber;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;

/**
 * iframe mode (host type C of ADR 2026-11-05-embeddable-admin): embedded
 * responses may be framed by the allow-listed origins, and no other response
 * changes. The host sends `X-Frame-Options: DENY` and its own CSP on every
 * response ({@see HostHeadersSubscriber}); the framing headers are applied
 * after it.
 */
final class EmbedIframeTest extends EmbedTestCase
{
    private const ORIGINS = [
        'https://admin.example.com',
        '*',
        'https://*.example.com',
        'https://admin.example.com/path',
        'javascript://admin.example.com',
        'https://user:secret@admin.example.com',
        'HTTPS://Admin.Example.com',
        'http://localhost:8080',
    ];

    public function testEmbeddedResponsesMayBeFramedByTheAllowListedOriginsOnly(): void
    {
        $this->boot(self::layoutEmbed(['mode' => 'iframe', 'frame_ancestors' => self::ORIGINS]));
        $this->client->loginUser($this->admin);

        $this->visit('/admin/support/tags');
        $headers = $this->client->getResponse()->headers;
        self::assertFalse($headers->has('X-Frame-Options'), 'frame-ancestors replaces X-Frame-Options on embedded responses');
        self::assertSame(
            [HostHeadersSubscriber::CSP, "frame-ancestors 'self' https://admin.example.com http://localhost:8080"],
            $headers->all('content-security-policy'),
            'the host CSP is kept; frame-ancestors is a second policy',
        );
        self::assertSame(['https://admin.example.com', 'http://localhost:8080'], $this->shared()['embed']['allowed_origins']);
        self::assertSame('iframe', $this->shared()['embed']['mode']);

        // Embedded error responses too: the frame shows them.
        self::assertSame(404, $this->statusOf('/admin/support/departments/999999/edit'));
        self::assertFalse($this->client->getResponse()->headers->has('X-Frame-Options'));

        // Not embedded: untouched.
        $this->visit('/support/agent/dashboard');
        $headers = $this->client->getResponse()->headers;
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame([HostHeadersSubscriber::CSP], $headers->all('content-security-policy'));
        self::assertSame(['https://admin.example.com', 'http://localhost:8080'], $this->shared()['embed']['allowed_origins']);

        $this->client->request('GET', '/host/missing');
        self::assertSame('DENY', $this->client->getResponse()->headers->get('X-Frame-Options'));
    }

    public function testWithNoOriginsOnlyTheAppItselfMayFrame(): void
    {
        $this->boot(self::layoutEmbed(['mode' => 'iframe']));
        $this->client->loginUser($this->admin);

        $this->visit('/admin/support/tags');
        self::assertSame([HostHeadersSubscriber::CSP, "frame-ancestors 'self'"], $this->client->getResponse()->headers->all('content-security-policy'));
        self::assertSame([], $this->shared()['embed']['allowed_origins']);
    }

    public function testTheEnvVarsTurnItOnWhenTheConfigLeavesThemOut(): void
    {
        $env = [
            'ESCALATED_EMBED' => 'true',
            'ESCALATED_EMBED_MODE' => 'iframe',
            'ESCALATED_EMBED_PREFIX' => '/backoffice/help/',
            'ESCALATED_EMBED_FRAME_ANCESTORS' => 'https://a.example, https://b.example:8443,*',
        ];
        foreach ($env as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }

        try {
            // An empty `ui.embed` block: every key falls back to its env var.
            $this->client = static::clientWithSchema([
                'escalated' => ['user_class' => TestUser::class, 'ui' => ['embed' => []]],
                'embed_host' => true,
            ]);
            $this->users();
            $this->client->loginUser($this->admin);

            $this->visit('/backoffice/help/tags');
            self::assertSame("frame-ancestors 'self' https://a.example https://b.example:8443", $this->client->getResponse()->headers->all('content-security-policy')[1] ?? null);
            self::assertSame('/backoffice/help', $this->shared()['embed']['base_url']);
            self::assertSame(404, $this->statusOf('/support/admin/tags'));
        } finally {
            foreach (array_keys($env) as $name) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            }
        }
    }
}
