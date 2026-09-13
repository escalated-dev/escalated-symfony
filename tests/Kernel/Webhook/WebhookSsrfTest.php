<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Webhook;

use Escalated\Symfony\Entity\Webhook;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The webhook admin accepted any URL that passed FILTER_VALIDATE_URL, so an
 * admin (or anyone holding an admin session) could point Escalated at the
 * host's own network: a local Redis, the cloud metadata endpoint, a private
 * service. Every ticket event then made the server POST there, and the
 * response body was stored and shown back in the delivery log.
 */
final class WebhookSsrfTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema();

        $admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = self::entityManager();
        $em->persist($admin);
        $em->flush();

        $this->client->loginUser($admin);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function internalUrls(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1:6379/'];
        yield 'localhost' => ['http://localhost:8080/hook'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'private 10/8' => ['http://10.0.0.5/hook'];
        yield 'private 192.168/16' => ['http://192.168.1.10/hook'];
        yield 'unspecified' => ['http://0.0.0.0/hook'];
        yield 'IPv6 loopback' => ['http://[::1]/hook'];
        yield 'non-http scheme' => ['gopher://127.0.0.1:6379/_INFO'];
    }

    #[DataProvider('internalUrls')]
    public function testAWebhookCannotBeSavedPointingAtAnInternalAddress(string $url): void
    {
        $this->store($url);

        self::assertSame(0, self::entityManager()->getRepository(Webhook::class)->count([]), "a webhook to $url was saved");
    }

    public function testAWebhookToAPublicAddressIsStillSaved(): void
    {
        $this->store('https://93.184.215.14/hooks/escalated');

        self::assertSame(1, self::entityManager()->getRepository(Webhook::class)->count([]));
    }

    private function store(string $url): void
    {
        $this->client->jsonRequest('POST', '/support/admin/webhooks', [
            'url' => $url,
            'events' => ['ticket.created'],
            'active' => true,
        ]);

        self::assertResponseRedirects();
        self::entityManager()->clear();
    }
}
