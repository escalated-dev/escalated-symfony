<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Webhook;
use Escalated\Symfony\Entity\WebhookDelivery;
use Escalated\Symfony\Http\WebhookHttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Delivers outbound webhook events to subscribed endpoints and records each
 * attempt as a WebhookDelivery row. Ports escalated-laravel's WebhookDispatcher.
 *
 * Deferred delivery
 * -----------------
 * ticket.created is emitted from a Doctrine postPersist callback, i.e. while a
 * flush is already in progress. Writing a WebhookDelivery + flushing there would
 * trigger a nested-flush error, and blocking the flush on a ~10s HTTP round-trip
 * would be unacceptable. So callers {@see enqueue()} events during the request
 * and the queue is drained by {@see flushPending()} on kernel/console terminate,
 * once the originating unit of work has fully committed.
 *
 * Retries
 * -------
 * On a non-2xx response or a transport error the send is retried, up to
 * {@see MAX_ATTEMPTS} attempts total, each attempt producing its own delivery
 * row. symfony/messenger is not a dependency, so retries run inline during the
 * terminate phase (after the response has been sent to the client). The
 * exponential backoff schedule a queue-backed host would honour is exposed via
 * {@see retryDelaySeconds()}.
 */
class WebhookDispatcher
{
    public const MAX_ATTEMPTS = 3;

    public const TIMEOUT_SECONDS = 10;

    public const RESPONSE_BODY_LIMIT = 2000;

    /** @var list<array{event: string, payload: array<string, mixed>}> */
    private array $pending = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WebhookHttpClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Queue an event for deferred delivery. Safe to call mid-flush: it only
     * appends to an in-memory buffer and never touches the database.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $event, array $payload): void
    {
        $this->pending[] = ['event' => $event, 'payload' => $payload];
    }

    /**
     * Drain the deferred queue, dispatching each buffered event. Invoked on
     * kernel.terminate / console.terminate. Idempotent — the buffer is cleared
     * before delivery so a second call is a no-op.
     */
    public function flushPending(): void
    {
        if ([] === $this->pending) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $item) {
            $this->dispatch($item['event'], $item['payload']);
        }
    }

    /**
     * Immediately dispatch an event to every active, subscribed webhook.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload): void
    {
        /** @var Webhook[] $webhooks */
        $webhooks = $this->em->getRepository(Webhook::class)->findBy(['active' => true]);

        foreach ($webhooks as $webhook) {
            if ($webhook->subscribedTo($event)) {
                $this->send($webhook, $event, $payload);
            }
        }
    }

    /**
     * Send one delivery to one endpoint, signing with HMAC-SHA256 when the
     * webhook has a secret and retrying (up to MAX_ATTEMPTS) on failure.
     *
     * @param array<string, mixed> $payload
     *
     * @return WebhookDelivery the delivery row for the final attempt
     */
    public function send(Webhook $webhook, string $event, array $payload, int $attempt = 1): WebhookDelivery
    {
        $body = json_encode([
            'event' => $event,
            'payload' => $payload,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type: application/json',
            'X-Escalated-Event: '.$event,
        ];

        if (null !== $webhook->getSecret() && '' !== $webhook->getSecret()) {
            $headers[] = 'X-Escalated-Signature: '.hash_hmac('sha256', $body, $webhook->getSecret());
        }

        $delivery = new WebhookDelivery();
        $delivery->setWebhook($webhook);
        $delivery->setEvent($event);
        $delivery->setPayload($payload);
        $delivery->setAttempts($attempt);
        $this->em->persist($delivery);
        $this->em->flush();

        try {
            $response = $this->http->post($webhook->getUrl(), $body, $headers, self::TIMEOUT_SECONDS);

            $delivery->setResponseCode($response['status']);
            $delivery->setResponseBody($this->truncate($response['body']));
            $delivery->setDeliveredAt(new \DateTimeImmutable());
            $this->em->flush();

            if (!$this->isSuccessful($response['status']) && $attempt < self::MAX_ATTEMPTS) {
                return $this->send($webhook, $event, $payload, $attempt + 1);
            }
        } catch (\Throwable $e) {
            $delivery->setResponseCode(0);
            $delivery->setResponseBody($this->truncate($e->getMessage()));
            $this->em->flush();

            $this->logger->warning(sprintf(
                '[Escalated\\WebhookDispatcher] delivery failed for webhook #%s event %s (attempt %d): %s',
                $webhook->getId() ?? '?',
                $event,
                $attempt,
                $e->getMessage(),
            ));

            if ($attempt < self::MAX_ATTEMPTS) {
                return $this->send($webhook, $event, $payload, $attempt + 1);
            }
        }

        return $delivery;
    }

    /**
     * Re-send a specific delivery (used by the admin "retry" action). Starts a
     * fresh attempt sequence.
     */
    public function retryDelivery(WebhookDelivery $delivery): void
    {
        $this->send($delivery->getWebhook(), $delivery->getEvent(), $delivery->getPayload() ?? [], 1);
    }

    /**
     * Exponential backoff schedule (120s, 240s, ...) matching the reference
     * implementation. Retries currently run inline (no queue backend), but this
     * is the delay a messenger/queue-backed host should apply between attempts.
     */
    public function retryDelaySeconds(int $attempt): int
    {
        return (int) (2 ** $attempt) * 30;
    }

    private function isSuccessful(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    private function truncate(string $value): string
    {
        return mb_substr($value, 0, self::RESPONSE_BODY_LIMIT);
    }
}
