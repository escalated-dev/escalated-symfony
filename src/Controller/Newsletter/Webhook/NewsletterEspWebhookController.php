<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Newsletter\Webhook;

use Escalated\Symfony\Service\Newsletter\NewsletterTracker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/escalated/webhooks/newsletter')]
class NewsletterEspWebhookController extends AbstractController
{
    public function __construct(
        private readonly NewsletterTracker $tracker,
        private readonly bool $enabled = false,
    ) {
    }

    #[Route('/postmark', methods: ['POST'])]
    public function postmark(Request $request): JsonResponse
    {
        $this->abortUnlessEnabled();
        $payload = $this->payload($request);
        $token = $this->tokenFromMessageId((string) ($payload['MessageID'] ?? ''));
        match ((string) ($payload['RecordType'] ?? '')) {
            'Open' => $this->tracker->recordOpen($token),
            'Click' => $this->tracker->recordClick($token, (string) ($payload['OriginalLink'] ?? '')),
            'Bounce' => $this->tracker->recordBounce(
                $token,
                $this->isHardPostmarkBounce((string) ($payload['Type'] ?? '')) ? 'hard' : 'soft',
                (string) ($payload['Description'] ?? ''),
            ),
            'SpamComplaint' => $this->tracker->recordComplaint($token),
            default => null,
        };

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/mailgun', methods: ['POST'])]
    public function mailgun(Request $request): JsonResponse
    {
        $this->abortUnlessEnabled();
        $payload = $this->payload($request);
        $eventData = \is_array($payload['event-data'] ?? null) ? $payload['event-data'] : [];
        $message = \is_array($eventData['message'] ?? null) ? $eventData['message'] : [];
        $headers = \is_array($message['headers'] ?? null) ? $message['headers'] : [];
        $deliveryStatus = \is_array($eventData['delivery-status'] ?? null) ? $eventData['delivery-status'] : [];
        $token = $this->tokenFromMessageId((string) ($headers['message-id'] ?? ''));
        match ((string) ($eventData['event'] ?? '')) {
            'opened' => $this->tracker->recordOpen($token),
            'clicked' => $this->tracker->recordClick($token, (string) ($eventData['url'] ?? '')),
            'failed' => $this->tracker->recordBounce(
                $token,
                'permanent' === ($eventData['severity'] ?? null) ? 'hard' : 'soft',
                (string) ($deliveryStatus['description'] ?? ''),
            ),
            'complained' => $this->tracker->recordComplaint($token),
            default => null,
        };

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/ses', methods: ['POST'])]
    public function ses(Request $request): JsonResponse
    {
        $this->abortUnlessEnabled();
        $payload = $this->payload($request);
        $body = $payload['Message'] ?? [];
        if (\is_string($body)) {
            $decoded = json_decode($body, true);
            $body = \is_array($decoded) ? $decoded : [];
        }
        $body = \is_array($body) ? $body : [];
        $mail = \is_array($body['mail'] ?? null) ? $body['mail'] : [];
        $click = \is_array($body['click'] ?? null) ? $body['click'] : [];
        $bounce = \is_array($body['bounce'] ?? null) ? $body['bounce'] : [];
        $token = $this->tokenFromMessageId((string) ($mail['messageId'] ?? ''));
        match ((string) ($body['eventType'] ?? '')) {
            'Open' => $this->tracker->recordOpen($token),
            'Click' => $this->tracker->recordClick($token, (string) ($click['link'] ?? '')),
            'Bounce' => $this->tracker->recordBounce(
                $token,
                'Permanent' === ($bounce['bounceType'] ?? null) ? 'hard' : 'soft',
                isset($bounce['bounceSubType']) ? (string) $bounce['bounceSubType'] : null,
            ),
            'Complaint' => $this->tracker->recordComplaint($token),
            default => null,
        };

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/sendgrid', methods: ['POST'])]
    public function sendgrid(Request $request): JsonResponse
    {
        $this->abortUnlessEnabled();
        $payload = $this->payload($request);
        $events = array_is_list($payload) ? $payload : [];
        foreach ($events as $event) {
            if (!\is_array($event)) {
                continue;
            }
            $token = $this->tokenFromMessageId((string) ($event['smtp-id'] ?? $event['sg_message_id'] ?? ''));
            match ((string) ($event['event'] ?? '')) {
                'open' => $this->tracker->recordOpen($token),
                'click' => $this->tracker->recordClick($token, (string) ($event['url'] ?? '')),
                'bounce' => $this->tracker->recordBounce(
                    $token,
                    'blocked' === ($event['type'] ?? null) ? 'hard' : 'soft',
                    isset($event['reason']) ? (string) $event['reason'] : null,
                ),
                'dropped' => $this->tracker->recordBounce($token, 'hard', isset($event['reason']) ? (string) $event['reason'] : null),
                'spamreport' => $this->tracker->recordComplaint($token),
                default => null,
            };
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * @return array<string|int, mixed>
     */
    private function payload(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        if (\is_array($decoded)) {
            return $decoded;
        }

        return $request->request->all();
    }

    private function tokenFromMessageId(string $messageId): string
    {
        if (preg_match('/n-\d+-([A-Za-z0-9]+)@/', $messageId, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^n-\d+-([A-Za-z0-9]+)$/', explode('@', $messageId)[0] ?? '', $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function isHardPostmarkBounce(string $type): bool
    {
        return in_array($type, ['HardBounce', 'BadEmailAddress', 'BlockedRecipient'], true);
    }

    private function abortUnlessEnabled(): void
    {
        if (!$this->enabled) {
            throw $this->createNotFoundException();
        }
    }
}
