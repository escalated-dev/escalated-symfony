<?php

declare(strict_types=1);

namespace Escalated\Symfony\Broadcasting;

/**
 * Wraps an event with broadcasting metadata.
 */
class BroadcastableEvent
{
    public function __construct(
        private readonly string $type,
        private readonly string $channel,
        private readonly array $payload,
        private readonly ?\DateTimeImmutable $occurredAt = null,
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt ?? new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'channel' => $this->channel,
            'payload' => $this->payload,
            'occurred_at' => $this->getOccurredAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
