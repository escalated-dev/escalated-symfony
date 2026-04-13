<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_attachments')]
#[ORM\Index(columns: ['ticket_id'], name: 'idx_attachment_ticket')]
#[ORM\Index(columns: ['reply_id'], name: 'idx_attachment_reply')]
class Attachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'ticket_id', nullable: true, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    #[ORM\ManyToOne(targetEntity: Reply::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'reply_id', nullable: true, onDelete: 'CASCADE')]
    private ?Reply $reply = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $originalFilename = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $storedFilename = '';

    #[ORM\Column(type: Types::STRING, length: 127, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $size = 0;

    /** @var string The storage disk (e.g. "local", "s3") */
    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $disk = 'local';

    /** @var string The relative path within the storage disk */
    #[ORM\Column(type: Types::STRING, length: 512)]
    private string $path = '';

    /**
     * Persisted download URL. When set, this value takes precedence over
     * any URL generated from the storage path. This is useful for
     * externally-hosted files or pre-signed URLs.
     */
    #[ORM\Column(type: Types::STRING, length: 1024, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // --- Getters and Setters ---

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicket(): ?Ticket
    {
        return $this->ticket;
    }

    public function setTicket(?Ticket $ticket): self
    {
        $this->ticket = $ticket;

        return $this;
    }

    public function getReply(): ?Reply
    {
        return $this->reply;
    }

    public function setReply(?Reply $reply): self
    {
        $this->reply = $reply;

        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): self
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getStoredFilename(): string
    {
        return $this->storedFilename;
    }

    public function setStoredFilename(string $storedFilename): self
    {
        $this->storedFilename = $storedFilename;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getDisk(): string
    {
        return $this->disk;
    }

    public function setDisk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Build the download URL for this attachment.
     *
     * Priority:
     *  1. Explicit URL stored on the entity (e.g. pre-signed S3 link).
     *  2. A fallback path-based route for the application to resolve.
     */
    public function resolveUrl(?string $baseStorageUrl = null): string
    {
        if (null !== $this->url && '' !== $this->url) {
            return $this->url;
        }

        if (null !== $baseStorageUrl && '' !== $this->path) {
            return rtrim($baseStorageUrl, '/').'/'.ltrim($this->path, '/');
        }

        // Ultimate fallback: let the application route handle it
        return '/escalated/attachments/'.$this->id.'/download';
    }

    /**
     * Serialize the attachment to an array suitable for JSON responses.
     * Guarantees the `url` key is always present.
     */
    public function toArray(?string $baseStorageUrl = null): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->originalFilename,
            'stored_filename' => $this->storedFilename,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'disk' => $this->disk,
            'path' => $this->path,
            'url' => $this->resolveUrl($baseStorageUrl),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
