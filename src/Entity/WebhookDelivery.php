<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single delivery attempt of a webhook event to a subscribed endpoint.
 *
 * One row is written per attempt; retries produce additional rows with an
 * incremented {@see $attempts} counter. Mirrors the WebhookDelivery model in
 * escalated-laravel / escalated-rails / escalated-django.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_webhook_deliveries')]
#[ORM\Index(columns: ['webhook_id', 'event'], name: 'idx_webhook_delivery_webhook_event')]
#[ORM\HasLifecycleCallbacks]
class WebhookDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Webhook::class, inversedBy: 'deliveries')]
    #[ORM\JoinColumn(name: 'webhook_id', nullable: false, onDelete: 'CASCADE')]
    private Webhook $webhook;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $event = '';

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $responseCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isSuccess(): bool
    {
        return null !== $this->responseCode && $this->responseCode >= 200 && $this->responseCode < 300;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWebhook(): Webhook
    {
        return $this->webhook;
    }

    public function setWebhook(Webhook $webhook): self
    {
        $this->webhook = $webhook;

        return $this;
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function setEvent(string $event): self
    {
        $this->event = $event;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /** @param array<string, mixed>|null $payload */
    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function getResponseCode(): ?int
    {
        return $this->responseCode;
    }

    public function setResponseCode(?int $responseCode): self
    {
        $this->responseCode = $responseCode;

        return $this;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function setResponseBody(?string $responseBody): self
    {
        $this->responseBody = $responseBody;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): self
    {
        $this->attempts = $attempts;

        return $this;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTimeImmutable $deliveredAt): self
    {
        $this->deliveredAt = $deliveredAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
