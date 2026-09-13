<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\InboundEmail;

use Escalated\Symfony\Controller\InboundEmailController;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Inbound email was implemented end to end -- controller, parsers, router,
 * service -- and unreachable:
 *
 *  - no routing file imported InboundEmailController, so the webhook 404'd;
 *  - nothing tagged the parsers `escalated.inbound_parser`, so the controller's
 *    tagged iterator was empty and every adapter was "unknown";
 *  - nothing supplied the shared secret the controller, router and threading
 *    service take, so the controller refused every request as unsigned.
 */
final class InboundEmailWiringTest extends EscalatedWebTestCase
{
    private const WEBHOOK = '/support/escalated/webhook/email/inbound';

    public function testTheInboundWebhookIsRoutedUnderTheBundlePrefix(): void
    {
        self::bootKernel();

        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $route = $router->getRouteCollection()->get('escalated.inbound_email.inbound');
        self::assertNotNull($route, 'the inbound email webhook route is not registered');
        self::assertSame(self::WEBHOOK, $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
    }

    public function testTheWebhookIsRoutedWithTheUiDisabled(): void
    {
        self::bootKernel(['escalated' => ['ui_enabled' => false]]);

        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        self::assertNotNull($router->getRouteCollection()->get('escalated.inbound_email.inbound'));
    }

    public function testTheControllerReceivesEveryShippedParser(): void
    {
        self::bootKernel();

        $controller = self::getContainer()->get(InboundEmailController::class);
        self::assertInstanceOf(InboundEmailController::class, $controller);

        $parsers = (new \ReflectionProperty(InboundEmailController::class, 'parsers'))->getValue($controller);
        $names = [];
        foreach ($parsers as $parser) {
            $names[] = $parser->name();
        }
        sort($names);

        self::assertSame(['mailgun', 'postmark', 'ses'], $names);
    }

    public function testWithoutAConfiguredSecretTheWebhookRefusesRequests(): void
    {
        $client = static::clientWithSchema();

        $client->request('POST', self::WEBHOOK.'?adapter=postmark', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ESCALATED_INBOUND_SECRET' => 'anything',
        ], (string) json_encode($this->postmarkPayload()));

        self::assertResponseStatusCodeSame(401);
    }

    public function testAPostmarkWebhookSignedWithTheConfiguredSecretCreatesATicket(): void
    {
        $client = static::clientWithSchema(['escalated' => ['inbound_secret' => 'inbound-test-secret']]);

        $client->request('POST', self::WEBHOOK.'?adapter=postmark', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ESCALATED_INBOUND_SECRET' => 'inbound-test-secret',
        ], (string) json_encode($this->postmarkPayload()));

        self::assertResponseStatusCodeSame(202);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('created', $body['status']);

        $ticket = self::entityManager()->getRepository(Ticket::class)->find($body['ticket_id']);
        self::assertInstanceOf(Ticket::class, $ticket);
        self::assertSame('Printer is on fire', $ticket->getSubject());
        self::assertSame('customer@example.com', $ticket->getGuestEmail());
    }

    public function testAWrongSecretIsRefused(): void
    {
        $client = static::clientWithSchema(['escalated' => ['inbound_secret' => 'inbound-test-secret']]);

        $client->request('POST', self::WEBHOOK.'?adapter=postmark', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ESCALATED_INBOUND_SECRET' => 'not-the-secret',
        ], (string) json_encode($this->postmarkPayload()));

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, self::entityManager()->getRepository(Ticket::class)->count([]));
    }

    /**
     * @return array<string, mixed>
     */
    private function postmarkPayload(): array
    {
        return [
            'FromName' => 'Customer',
            'MessageID' => '5f1c2d3e-0000-4000-8000-000000000001',
            'FromFull' => ['Email' => 'customer@example.com', 'Name' => 'Customer'],
            'To' => 'support@support.example.com',
            'ToFull' => [['Email' => 'support@support.example.com', 'Name' => '']],
            'OriginalRecipient' => 'support@support.example.com',
            'Subject' => 'Printer is on fire',
            'TextBody' => 'It started smoking about ten minutes ago.',
            'HtmlBody' => '<p>It started smoking about ten minutes ago.</p>',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<first-message@mail.client>'],
            ],
            'Attachments' => [],
        ];
    }
}
