<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row of what a plugin keeps through `ctx.store` and `ctx.config`
 * (`escalated_plugin_store` in the plugin contract). `plugin` is the plugin's
 * name, not its slug. Config is the row whose collection and key are both
 * `__config__`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_plugin_store')]
#[ORM\Index(columns: ['plugin', 'collection', 'key'], name: 'idx_plugin_store_lookup')]
#[ORM\HasLifecycleCallbacks]
class PluginStoreRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $plugin;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $collection;

    // `key` is reserved on MySQL; the backticks make Doctrine quote it.
    #[ORM\Column(name: '`key`', type: Types::STRING, length: 191, nullable: true)]
    private ?string $key;

    /** Any JSON value the plugin stored. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private mixed $data = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $plugin, string $collection, ?string $key, mixed $data = null)
    {
        $this->plugin = $plugin;
        $this->collection = $collection;
        $this->key = $key;
        $this->data = $data;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlugin(): string
    {
        return $this->plugin;
    }

    public function getCollection(): string
    {
        return $this->collection;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function setData(mixed $data): self
    {
        $this->data = $data;
        // A changed array inside the same object is not always seen as a
        // change; touching updatedAt makes the update certain.
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
