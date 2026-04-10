<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_custom_object_records')]
#[ORM\HasLifecycleCallbacks]
class CustomObjectRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomObject::class, inversedBy: 'records')]
    #[ORM\JoinColumn(name: 'custom_object_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CustomObject $customObject;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::JSON)]
    private array $data = [];

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $linkedEntityType = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $linkedEntityId = null;

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
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomObject(): CustomObject
    {
        return $this->customObject;
    }

    public function setCustomObject(CustomObject $customObject): self
    {
        $this->customObject = $customObject;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getFieldValue(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function setFieldValue(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function getLinkedEntityType(): ?string
    {
        return $this->linkedEntityType;
    }

    public function setLinkedEntityType(?string $linkedEntityType): self
    {
        $this->linkedEntityType = $linkedEntityType;

        return $this;
    }

    public function getLinkedEntityId(): ?int
    {
        return $this->linkedEntityId;
    }

    public function setLinkedEntityId(?int $linkedEntityId): self
    {
        $this->linkedEntityId = $linkedEntityId;

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
