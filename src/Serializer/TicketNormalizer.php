<?php

declare(strict_types=1);

namespace Escalated\Symfony\Serializer;

use Escalated\Symfony\Entity\Ticket;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Adds computed fields to serialized Ticket objects.
 *
 * The frontend expects these convenience properties on every ticket:
 *   - requester_name
 *   - requester_email
 *   - last_reply_at
 *   - last_reply_author
 *   - is_live_chat
 *   - is_snoozed
 */
#[Autoconfigure(tags: ['serializer.normalizer'])]
class TicketNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'ticket_normalizer_already_called';

    /**
     * @param Ticket $object
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $context[self::ALREADY_CALLED] = true;

        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($object, $format, $context);

        // requester_name: guest name when available, null otherwise
        if (!array_key_exists('requester_name', $data)) {
            $data['requester_name'] = $object->getGuestName();
        }

        // requester_email: guest email when available, null otherwise
        if (!array_key_exists('requester_email', $data)) {
            $data['requester_email'] = $object->getGuestEmail();
        }

        // last_reply_at / last_reply_author: derived from the replies collection
        if (!array_key_exists('last_reply_at', $data) || !array_key_exists('last_reply_author', $data)) {
            $lastReply = $object->getReplies()->first() ?: null;

            if (!array_key_exists('last_reply_at', $data)) {
                $data['last_reply_at'] = $lastReply?->getCreatedAt()->format(\DateTimeInterface::ATOM);
            }

            if (!array_key_exists('last_reply_author', $data)) {
                $data['last_reply_author'] = null;
                if (null !== $lastReply) {
                    $meta = $lastReply->getMetadata();
                    $data['last_reply_author'] = $meta['author_name'] ?? null;
                }
            }
        }

        // is_live_chat: status is "live" and channel is "chat"
        if (!array_key_exists('is_live_chat', $data)) {
            $data['is_live_chat'] = Ticket::STATUS_LIVE === $object->getStatus()
                && Ticket::CHANNEL_CHAT === $object->getChannel();
        }

        // is_snoozed: snoozed_until is set and in the future
        if (!array_key_exists('is_snoozed', $data)) {
            $snoozedUntil = $object->getSnoozedUntil();
            $data['is_snoozed'] = null !== $snoozedUntil && $snoozedUntil > new \DateTimeImmutable();
        }

        return $data;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof Ticket;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Ticket::class => false,
        ];
    }
}
