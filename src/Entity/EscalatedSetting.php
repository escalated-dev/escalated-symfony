<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Repository\EscalatedSettingRepository;

/**
 * Small key/value store for runtime-mutable Escalated settings.
 *
 * Mirrors the {@code EscalatedSettings} / {@code escalated_settings}
 * pair already in the Laravel / Rails / Django / Adonis / WordPress
 * ports. Values are stored as strings; callers that need typed access
 * should go through {@see \Escalated\Symfony\Service\SettingsService}.
 */
#[ORM\Entity(repositoryClass: EscalatedSettingRepository::class)]
#[ORM\Table(name: 'escalated_settings')]
#[ORM\HasLifecycleCallbacks]
class EscalatedSetting
{
    #[ORM\Id]
    #[ORM\Column(name: '`key`', type: Types::STRING, length: 191)]
    private string $key = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key = '', ?string $value = null)
    {
        $this->key = $key;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
