<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Controller;

use Escalated\Symfony\Controller\InboundEmailController;
use Escalated\Symfony\Mail\Inbound\InboundEmailParser;
use Escalated\Symfony\Mail\Inbound\InboundEmailService;
use Escalated\Symfony\Mail\Inbound\InboundMessage;
use Escalated\Symfony\Mail\Inbound\PendingAttachment;
use Escalated\Symfony\Mail\Inbound\ProcessResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP-level tests for {@see InboundEmailController}.
 *
 * Mirrors the Go (escalated-go#34), .NET (escalated-dotnet#28),
 * Spring (escalated-spring#31), and Phoenix (escalated-phoenix#40)
 * controller-test ports. Exercises signature verification, adapter
 * dispatch, and the full response shape produced by
 * {@see InboundEmailService::process()}.
 *
 * The real {@see InboundEmailService} is replaced with a PHPUnit mock
 * so the test doesn't need a DB + Doctrine — the orchestration itself
 * is covered by tests/Mail/Inbound/InboundEmailServiceTest.php.
 */
class InboundEmailControllerTest extends TestCase
{
    private const SECRET = 'test-inbound-secret';

    private InboundEmailService&MockObject $service;

    protected function setUp(): void
    {
        $this->service = $this->createMock(InboundEmailService::class);
    }

    private function controller(array $parsers = []): InboundEmailController
    {
        if ([] === $parsers) {
            $parsers = [$this->stubParser('postmark')];
        }

        return new InboundEmailController($this->service, $parsers, self::SECRET);
    }

    private function stubParser(string $name): InboundEmailParser
    {
        return new class($name) implements InboundEmailParser {
            public function __construct(private readonly string $n)
            {
            }

            public function name(): string
            {
                return $this->n;
            }

            public function parse(array $rawPayload): InboundMessage
            {
                return new InboundMessage(
                    fromEmail: $rawPayload['From'] ?? '',
                    fromName: $rawPayload['FromName'] ?? null,
                    toEmail: $rawPayload['To'] ?? '',
                    subject: $rawPayload['Subject'] ?? '',
                    bodyText: $rawPayload['TextBody'] ?? null,
                );
            }
        };
    }

    private function request(array $options): Request
    {
        $queryAdapter = $options['adapter'] ?? null;
        $headerAdapter = $options['header_adapter'] ?? null;
        $secret = $options['secret'] ?? null;
        $body = $options['body'] ?? '{}';

        $server = [];
        if (null !== $secret) {
            $server['HTTP_X_ESCALATED_INBOUND_SECRET'] = $secret;
        }
        if (null !== $headerAdapter) {
            $server['HTTP_X_ESCALATED_ADAPTER'] = $headerAdapter;
        }

        $query = null !== $queryAdapter ? ['adapter' => $queryAdapter] : [];

        return new Request(
            query: $query,
            request: [],
            attributes: [],
            cookies: [],
            files: [],
            server: $server,
            content: $body,
        );
    }

    public function testNewTicketReturnsCreatedOutcome(): void
    {
        $this->service->method('process')->willReturn(
            new ProcessResult(
                outcome: InboundEmailService::OUTCOME_CREATED_NEW,
                ticketId: 101,
                replyId: null,
                pendingAttachmentDownloads: [],
            )
        );

        $controller = $this->controller();
        $response = $controller->inbound($this->request([
            'adapter' => 'postmark',
            'secret' => self::SECRET,
            'body' => '{"From":"alice@example.com","To":"support@example.com","Subject":"Help","TextBody":"Broken"}',
        ]));

        $this->assertSame(202, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('created_new', $body['outcome']);
        $this->assertSame('created', $body['status']);
        $this->assertSame(101, $body['ticket_id']);
        $this->assertNull($body['reply_id']);
        $this->assertSame([], $body['pending_attachment_downloads']);
    }

    public function testMatchedReplyReturnsRepliedToExisting(): void
    {
        $this->service->method('process')->willReturn(
            new ProcessResult(
                outcome: InboundEmailService::OUTCOME_REPLIED_TO_EXISTING,
                ticketId: 55,
                replyId: 202,
                pendingAttachmentDownloads: [],
            )
        );

        $controller = $this->controller();
        $response = $controller->inbound($this->request([
            'adapter' => 'postmark',
            'secret' => self::SECRET,
            'body' => '{"From":"alice@example.com","To":"support@example.com","Subject":"Re: Help","TextBody":"More"}',
        ]));

        $this->assertSame(202, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('replied_to_existing', $body['outcome']);
        $this->assertSame('matched', $body['status']);
        $this->assertSame(55, $body['ticket_id']);
        $this->assertSame(202, $body['reply_id']);
    }

    public function testSkippedReturnsSkippedOutcome(): void
    {
        $this->service->method('process')->willReturn(
            new ProcessResult(
                outcome: InboundEmailService::OUTCOME_SKIPPED,
                ticketId: null,
                replyId: null,
                pendingAttachmentDownloads: [],
            )
        );

        $controller = $this->controller();
        $response = $controller->inbound($this->request([
            'adapter' => 'postmark',
            'secret' => self::SECRET,
            'body' => '{"From":"no-reply@sns.amazonaws.com","To":"support@example.com","Subject":"SubscriptionConfirmation","TextBody":""}',
        ]));

        $this->assertSame(202, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('skipped', $body['outcome']);
        $this->assertSame('skipped', $body['status']);
        $this->assertNull($body['ticket_id']);
    }

    public function testSurfacesProviderHostedAttachments(): void
    {
        $this->service->method('process')->willReturn(
            new ProcessResult(
                outcome: InboundEmailService::OUTCOME_CREATED_NEW,
                ticketId: 101,
                replyId: null,
                pendingAttachmentDownloads: [
                    new PendingAttachment(
                        name: 'large.pdf',
                        contentType: 'application/pdf',
                        sizeBytes: 10_000_000,
                        downloadUrl: 'https://mailgun.example/att/large',
                    ),
                ],
            )
        );

        $controller = $this->controller();
        $response = $controller->inbound($this->request([
            'adapter' => 'postmark',
            'secret' => self::SECRET,
            'body' => '{"From":"alice@example.com","To":"support@example.com","Subject":"Attached","TextBody":"x"}',
        ]));

        $this->assertSame(202, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertCount(1, $body['pending_attachment_downloads']);
        $this->assertSame('large.pdf', $body['pending_attachment_downloads'][0]['name']);
        $this->assertSame(
            'https://mailgun.example/att/large',
            $body['pending_attachment_downloads'][0]['download_url']
        );
    }

    public function testMissingSecretReturns401(): void
    {
        $this->service->expects($this->never())->method('process');
        $controller = $this->controller();

        $response = $controller->inbound($this->request([
            'adapter' => 'postmark',
            // no secret
            'body' => '{"From":"a@b.com"}',
        ]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testBadSecretReturns401(): void
    {
        $this->service->expects($this->never())->method('process');
        $controller = $this->controller();

        $response = $controller->inbound($this->request([
            'adapter' => 'postmark',
            'secret' => 'wrong-secret',
            'body' => '{"From":"a@b.com"}',
        ]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testMissingAdapterReturns400(): void
    {
        $this->service->expects($this->never())->method('process');
        $controller = $this->controller();

        $response = $controller->inbound($this->request([
            // no adapter
            'secret' => self::SECRET,
            'body' => '{}',
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('missing adapter', $body['error']);
    }

    public function testUnknownAdapterReturns400(): void
    {
        $this->service->expects($this->never())->method('process');
        $controller = $this->controller();

        $response = $controller->inbound($this->request([
            'adapter' => 'nonesuch',
            'secret' => self::SECRET,
            'body' => '{}',
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('nonesuch', $body['error']);
    }

    public function testInvalidJsonBodyReturns400(): void
    {
        $this->service->expects($this->never())->method('process');
        $controller = $this->controller();

        $response = $controller->inbound($this->request([
            'adapter' => 'postmark',
            'secret' => self::SECRET,
            'body' => 'not json at all',
        ]));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAdapterHeaderIsAcceptedAsFallback(): void
    {
        $this->service->method('process')->willReturn(
            new ProcessResult(
                outcome: InboundEmailService::OUTCOME_SKIPPED,
                ticketId: null,
                replyId: null,
                pendingAttachmentDownloads: [],
            )
        );

        $controller = $this->controller();
        $response = $controller->inbound($this->request([
            'header_adapter' => 'postmark',
            'secret' => self::SECRET,
            'body' => '{"From":"no-reply@sns.amazonaws.com","To":"s@x.com","Subject":"","TextBody":""}',
        ]));

        $this->assertSame(202, $response->getStatusCode());
    }
}
