<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Escalated\Symfony\Entity\Webhook;
use Escalated\Symfony\Entity\WebhookDelivery;
use Escalated\Symfony\Http\WebhookTransportException;
use Escalated\Symfony\Service\WebhookDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WebhookDispatcherTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->persisted = [];
    }

    public function testDispatchOnlySendsToActiveSubscribedWebhooks(): void
    {
        $subscribed = (new Webhook())->setUrl('https://a.test/hook')->setEvents(['ticket.created']);
        $other = (new Webhook())->setUrl('https://b.test/hook')->setEvents(['reply.created']);

        $http = RecordingWebhookHttpClient::alwaysReturns(200, 'ok');
        $dispatcher = new WebhookDispatcher($this->em([$subscribed, $other]), $http, new NullLogger());

        $dispatcher->dispatch('ticket.created', ['ticket' => ['id' => 1]]);

        $this->assertCount(1, $http->calls);
        $this->assertSame('https://a.test/hook', $http->calls[0]['url']);
    }

    public function testSendBuildsSignedRequestWhenSecretPresent(): void
    {
        $webhook = (new Webhook())->setUrl('https://a.test/hook')->setSecret('topsecret')->setEvents(['ticket.created']);

        $http = RecordingWebhookHttpClient::alwaysReturns(200, 'ok');
        $dispatcher = new WebhookDispatcher($this->em([]), $http, new NullLogger());

        $dispatcher->send($webhook, 'ticket.created', ['ticket' => ['id' => 5]]);

        $headers = $http->lastHeaders();
        $this->assertContains('Content-Type: application/json', $headers);
        $this->assertContains('X-Escalated-Event: ticket.created', $headers);

        $expected = 'X-Escalated-Signature: '.hash_hmac('sha256', $http->lastBody(), 'topsecret');
        $this->assertContains($expected, $headers);
    }

    public function testSendOmitsSignatureWhenNoSecret(): void
    {
        $webhook = (new Webhook())->setUrl('https://a.test/hook')->setEvents(['ticket.created']);

        $http = RecordingWebhookHttpClient::alwaysReturns(200, 'ok');
        $dispatcher = new WebhookDispatcher($this->em([]), $http, new NullLogger());

        $dispatcher->send($webhook, 'ticket.created', []);

        foreach ($http->lastHeaders() as $header) {
            $this->assertStringStartsNotWith('X-Escalated-Signature:', $header);
        }
    }

    public function testBodyCarriesEventPayloadAndTimestamp(): void
    {
        $webhook = (new Webhook())->setUrl('https://a.test/hook')->setEvents(['ticket.created']);

        $http = RecordingWebhookHttpClient::alwaysReturns(200, 'ok');
        $dispatcher = new WebhookDispatcher($this->em([]), $http, new NullLogger());

        $payload = ['ticket' => ['id' => 9, 'reference' => 'ESC-9']];
        $dispatcher->send($webhook, 'ticket.created', $payload);

        $decoded = json_decode($http->lastBody(), true);
        $this->assertSame('ticket.created', $decoded['event']);
        $this->assertSame($payload, $decoded['payload']);
        $this->assertNotEmpty($decoded['timestamp']);
        $this->assertInstanceOf(\DateTimeInterface::class, \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $decoded['timestamp']));
    }

    public function testSuccessRecordsDeliveryAndDoesNotRetry(): void
    {
        $webhook = (new Webhook())->setUrl('https://a.test/hook')->setEvents(['ticket.created']);

        $http = RecordingWebhookHttpClient::alwaysReturns(201, 'created');
        $dispatcher = new WebhookDispatcher($this->em([]), $http, new NullLogger());

        $dispatcher->send($webhook, 'ticket.created', ['ticket' => ['id' => 1]]);

        $deliveries = $this->deliveries();
        $this->assertCount(1, $deliveries);
        $this->assertCount(1, $http->calls);

        $delivery = $deliveries[0];
        $this->assertSame(201, $delivery->getResponseCode());
        $this->assertSame('created', $delivery->getResponseBody());
        $this->assertSame(1, $delivery->getAttempts());
        $this->assertNotNull($delivery->getDeliveredAt());
        $this->assertTrue($delivery->isSuccess());
    }

    public function testTruncatesResponseBodyToLimit(): void
    {
        $webhook = (new Webhook())->setUrl('https://a.test/hook')->setEvents(['ticket.created']);

        $http = RecordingWebhookHttpClient::alwaysReturns(200, str_repeat('x', 5000));
        $dispatcher = new WebhookDispatcher($this->em([]), $http, new NullLogger());

        $dispatcher->send($webhook, 'ticket.created', []);

        $this->assertSame(WebhookDispatcher::RESPONSE_BODY_LIMIT, mb_strlen((string) $this->deliveries()[0]->getResponseBody()));
    }

    public function testRetriesUpToMaxAttemptsOnNon2xx(): void
    {
        $webhook = (new Webhook())->setUrl('https://a.test/hook')->setEvents(['ticket.created']);

        $http = RecordingWebhookHttpClient::alwaysReturns(500, 'boom');
        $dispatcher = new WebhookDispatcher($this->em([]), $http, new NullLogger());

        $dispatcher->send($webhook, 'ticket.created', []);

        $this->assertCount(WebhookDispatcher::MAX_ATTEMPTS, $http->calls);
        $deliveries = $this->deliveries();
        $this->assertCount(WebhookDispatcher::MAX_ATTEMPTS, $deliveries);
        $this->assertSame([1, 2, 3], array_map(static fn (WebhookDelivery $d) => $d->getAttempts(), $deliveries));
        $this->assertSame(500, $deliveries[2]->getResponseCode());
    }

    public function testTransportExceptionRecordsZeroAndRetries(): void
    {
        $webhook = (new Webhook())->setUrl('https://a.test/hook')->setEvents(['ticket.created']);

        $http = new RecordingWebhookHttpClient(static function (): array {
            throw new WebhookTransportException('connection refused');
        });
        $dispatcher = new WebhookDispatcher($this->em([]), $http, new NullLogger());

        $dispatcher->send($webhook, 'ticket.created', []);

        $deliveries = $this->deliveries();
        $this->assertCount(WebhookDispatcher::MAX_ATTEMPTS, $deliveries);
        $this->assertSame(0, $deliveries[0]->getResponseCode());
        $this->assertSame('connection refused', $deliveries[0]->getResponseBody());
    }

    public function testStopsRetryingOnceSuccessful(): void
    {
        $webhook = (new Webhook())->setUrl('https://a.test/hook')->setEvents(['ticket.created']);

        // Fail first, succeed on the second attempt.
        $http = new RecordingWebhookHttpClient(static fn (int $n): array => $n < 2
            ? ['status' => 503, 'body' => 'unavailable']
            : ['status' => 200, 'body' => 'ok']);
        $dispatcher = new WebhookDispatcher($this->em([]), $http, new NullLogger());

        $dispatcher->send($webhook, 'ticket.created', []);

        $this->assertCount(2, $http->calls);
        $this->assertCount(2, $this->deliveries());
    }

    public function testRetryDeliveryStartsFreshAttempt(): void
    {
        $webhook = (new Webhook())->setUrl('https://a.test/hook')->setEvents(['ticket.created']);
        $original = (new WebhookDelivery())
            ->setWebhook($webhook)
            ->setEvent('ticket.created')
            ->setPayload(['ticket' => ['id' => 3]])
            ->setAttempts(3);

        $http = RecordingWebhookHttpClient::alwaysReturns(200, 'ok');
        $dispatcher = new WebhookDispatcher($this->em([]), $http, new NullLogger());

        $dispatcher->retryDelivery($original);

        $this->assertCount(1, $http->calls);
        $new = $this->deliveries();
        $this->assertCount(1, $new);
        $this->assertSame(1, $new[0]->getAttempts());
        $this->assertSame('ticket.created', $new[0]->getEvent());
    }

    public function testEnqueueDefersDeliveryUntilFlush(): void
    {
        $webhook = (new Webhook())->setUrl('https://a.test/hook')->setEvents(['ticket.created']);

        $http = RecordingWebhookHttpClient::alwaysReturns(200, 'ok');
        $dispatcher = new WebhookDispatcher($this->em([$webhook]), $http, new NullLogger());

        $dispatcher->enqueue('ticket.created', ['ticket' => ['id' => 1]]);

        // Nothing sent or persisted yet.
        $this->assertCount(0, $http->calls);
        $this->assertCount(0, $this->deliveries());

        $dispatcher->flushPending();

        $this->assertCount(1, $http->calls);
        $this->assertCount(1, $this->deliveries());
    }

    public function testFlushPendingIsIdempotent(): void
    {
        $webhook = (new Webhook())->setUrl('https://a.test/hook')->setEvents(['ticket.created']);

        $http = RecordingWebhookHttpClient::alwaysReturns(200, 'ok');
        $dispatcher = new WebhookDispatcher($this->em([$webhook]), $http, new NullLogger());

        $dispatcher->enqueue('ticket.created', []);
        $dispatcher->flushPending();
        $dispatcher->flushPending();

        $this->assertCount(1, $http->calls);
    }

    public function testRetryDelaySchedule(): void
    {
        $http = RecordingWebhookHttpClient::alwaysReturns(200);
        $dispatcher = new WebhookDispatcher($this->em([]), $http, new NullLogger());

        $this->assertSame(60, $dispatcher->retryDelaySeconds(1));
        $this->assertSame(120, $dispatcher->retryDelaySeconds(2));
        $this->assertSame(240, $dispatcher->retryDelaySeconds(3));
    }

    /**
     * @param Webhook[] $webhooks
     */
    private function em(array $webhooks): EntityManagerInterface
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn($webhooks);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        return $em;
    }

    /**
     * @return WebhookDelivery[]
     */
    private function deliveries(): array
    {
        return array_values(array_filter(
            $this->persisted,
            static fn (object $e): bool => $e instanceof WebhookDelivery,
        ));
    }
}
