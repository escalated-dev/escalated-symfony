<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A knowledge-base article. Mirrors the Laravel Article model: draft or
 * published, optionally filed under a category, with view and helpfulness
 * counters.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_articles')]
#[ORM\UniqueConstraint(name: 'escalated_articles_slug_unique', columns: ['slug'])]
#[ORM\Index(columns: ['status'], name: 'idx_article_status')]
#[ORM\HasLifecycleCallbacks]
class Article
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ArticleCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: true, onDelete: 'SET NULL')]
    private ?ArticleCategory $category = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'author_id', type: Types::STRING, length: 255, nullable: true)]
    private ?string $authorId = null;

    #[ORM\Column(name: 'view_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $viewCount = 0;

    #[ORM\Column(name: 'helpful_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $helpfulCount = 0;

    #[ORM\Column(name: 'not_helpful_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $notHelpfulCount = 0;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

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

    public function isPublished(): bool
    {
        return self::STATUS_PUBLISHED === $this->status;
    }

    public function incrementViews(): void
    {
        ++$this->viewCount;
    }

    public function markHelpful(): void
    {
        ++$this->helpfulCount;
    }

    public function markNotHelpful(): void
    {
        ++$this->notHelpfulCount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?ArticleCategory
    {
        return $this->category;
    }

    public function setCategory(?ArticleCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAuthorId(): ?string
    {
        return $this->authorId;
    }

    public function setAuthorId(?string $authorId): static
    {
        $this->authorId = $authorId;

        return $this;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function getHelpfulCount(): int
    {
        return $this->helpfulCount;
    }

    public function getNotHelpfulCount(): int
    {
        return $this->notHelpfulCount;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

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
