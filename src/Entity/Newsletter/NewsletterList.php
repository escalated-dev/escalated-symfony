<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity\Newsletter;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Doctrine\UserIdType;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_newsletter_lists')]
class NewsletterList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var 'static'|'dynamic' */
    #[ORM\Column(length: 16)]
    private string $kind = 'static';

    /** @var array{rules: array<int, array{field: string, op: string, value: mixed}>}|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $filterJson = null;

    #[ORM\Column(name: 'created_by', type: UserIdType::NAME, nullable: true)]
    private int|string|null $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $v): self
    {
        $this->name = $v;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $v): self
    {
        $this->description = $v;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $v): self
    {
        $this->kind = $v;

        return $this;
    }

    public function getFilterJson(): ?array
    {
        return $this->filterJson;
    }

    public function setFilterJson(?array $v): self
    {
        $this->filterJson = $v;

        return $this;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?string $v): self
    {
        $this->createdBy = $v;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
