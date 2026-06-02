<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity\Newsletter;

use Doctrine\DBAL\Types\Types;
use Escalated\Symfony\Doctrine\UserIdType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_newsletters')]
class Newsletter
{
    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'paused', 'failed'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 998)]
    private string $subject = '';

    #[ORM\Column(name: 'from_email', length: 320)]
    private string $fromEmail = '';

    #[ORM\Column(name: 'from_name', length: 255, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(name: 'reply_to', length: 320, nullable: true)]
    private ?string $replyTo = null;

    #[ORM\Column(name: 'target_list_id', type: Types::INTEGER)]
    private int $targetListId = 0;

    #[ORM\Column(name: 'template_id', type: Types::INTEGER, nullable: true)]
    private ?int $templateId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $theme = null;

    #[ORM\Column(name: 'body_markdown', type: Types::TEXT, nullable: true)]
    private ?string $bodyMarkdown = null;

    #[ORM\Column(length: 16)]
    private string $status = 'draft';

    #[ORM\Column(name: 'scheduled_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $scheduledAt = null;

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $sentAt = null;

    #[ORM\Column(name: 'created_by', type: UserIdType::NAME, nullable: true)]
    private int|string|null $createdBy = null;

    #[ORM\Column(name: 'sent_by', type: UserIdType::NAME, nullable: true)]
    private int|string|null $sentBy = null;

    #[ORM\Column(name: 'summary_total', type: Types::INTEGER)]
    private int $summaryTotal = 0;

    #[ORM\Column(name: 'summary_sent', type: Types::INTEGER)]
    private int $summarySent = 0;

    #[ORM\Column(name: 'summary_opened', type: Types::INTEGER)]
    private int $summaryOpened = 0;

    #[ORM\Column(name: 'summary_clicked', type: Types::INTEGER)]
    private int $summaryClicked = 0;

    #[ORM\Column(name: 'summary_bounced', type: Types::INTEGER)]
    private int $summaryBounced = 0;

    #[ORM\Column(name: 'summary_complained', type: Types::INTEGER)]
    private int $summaryComplained = 0;

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

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $v): self
    {
        $this->subject = $v;

        return $this;
    }

    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    public function setFromEmail(string $v): self
    {
        $this->fromEmail = $v;

        return $this;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function setFromName(?string $v): self
    {
        $this->fromName = $v;

        return $this;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function setReplyTo(?string $v): self
    {
        $this->replyTo = $v;

        return $this;
    }

    public function getTargetListId(): int
    {
        return $this->targetListId;
    }

    public function setTargetListId(int $v): self
    {
        $this->targetListId = $v;

        return $this;
    }

    public function getTemplateId(): ?int
    {
        return $this->templateId;
    }

    public function setTemplateId(?int $v): self
    {
        $this->templateId = $v;

        return $this;
    }

    public function getTheme(): ?string
    {
        return $this->theme;
    }

    public function setTheme(?string $v): self
    {
        $this->theme = $v;

        return $this;
    }

    public function getBodyMarkdown(): ?string
    {
        return $this->bodyMarkdown;
    }

    public function setBodyMarkdown(?string $v): self
    {
        $this->bodyMarkdown = $v;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $v): self
    {
        $this->status = $v;

        return $this;
    }

    public function getScheduledAt(): ?\DateTimeInterface
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeInterface $v): self
    {
        $this->scheduledAt = $v;

        return $this;
    }

    public function getSentAt(): ?\DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeInterface $v): self
    {
        $this->sentAt = $v;

        return $this;
    }

    public function getSummaryTotal(): int
    {
        return $this->summaryTotal;
    }

    public function setSummaryTotal(int $v): self
    {
        $this->summaryTotal = $v;

        return $this;
    }

    public function getSummarySent(): int
    {
        return $this->summarySent;
    }

    public function incrementSummarySent(int $by = 1): self
    {
        $this->summarySent += $by;

        return $this;
    }

    public function getSummaryOpened(): int
    {
        return $this->summaryOpened;
    }

    public function incrementSummaryOpened(int $by = 1): self
    {
        $this->summaryOpened += $by;

        return $this;
    }

    public function getSummaryClicked(): int
    {
        return $this->summaryClicked;
    }

    public function incrementSummaryClicked(int $by = 1): self
    {
        $this->summaryClicked += $by;

        return $this;
    }

    public function getSummaryBounced(): int
    {
        return $this->summaryBounced;
    }

    public function incrementSummaryBounced(int $by = 1): self
    {
        $this->summaryBounced += $by;

        return $this;
    }

    public function getSummaryComplained(): int
    {
        return $this->summaryComplained;
    }

    public function incrementSummaryComplained(int $by = 1): self
    {
        $this->summaryComplained += $by;

        return $this;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
