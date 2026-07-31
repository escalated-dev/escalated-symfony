<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Outbound webhook subscription — an admin-registered HTTP endpoint that
 * receives a signed POST for every subscribed domain event.
 *
 * Mirrors the Webhook model shipped in escalated-laravel / escalated-rails /
 * escalated-django. Delivery + retry logic lives in WebhookDispatcher.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_webhooks')]
#[ORM\HasLifecycleCallbacks]
class Webhook
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $url = '';

    /** @var array<int, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $events = [];

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $secret = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    /** @var Collection<int, WebhookDelivery> */
    #[ORM\OneToMany(targetEntity: WebhookDelivery::class, mappedBy: 'webhook', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $deliveries;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->deliveries = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function subscribedTo(string $event): bool
    {
        return \in_array($event, $this->events, true);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /** @return array<int, string> */
    public function getEvents(): array
    {
        return $this->events;
    }

    /** @param array<int, string> $events */
    public function setEvents(array $events): self
    {
        $this->events = array_values($events);

        return $this;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function setSecret(?string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /** @return Collection<int, WebhookDelivery> */
    public function getDeliveries(): Collection
    {
        return $this->deliveries;
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
