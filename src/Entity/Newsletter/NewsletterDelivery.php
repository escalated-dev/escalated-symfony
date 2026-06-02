<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity\Newsletter;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_newsletter_deliveries')]
class NewsletterDelivery
{
    public const STATUSES = ['pending', 'queued', 'sent', 'bounced', 'complained', 'suppressed', 'failed'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(name: 'newsletter_id', type: Types::INTEGER)]
    private int $newsletterId = 0;

    #[ORM\Column(name: 'contact_id', type: Types::INTEGER)]
    private int $contactId = 0;

    #[ORM\Column(name: 'email_at_send', length: 320)]
    private string $emailAtSend = '';

    #[ORM\Column(length: 16)]
    private string $status = 'pending';

    #[ORM\Column(name: 'tracking_token', length: 40, unique: true)]
    private string $trackingToken = '';

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $sentAt = null;

    #[ORM\Column(name: 'opened_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $openedAt = null;

    #[ORM\Column(name: 'last_clicked_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastClickedAt = null;

    #[ORM\Column(name: 'clicks_count', type: Types::INTEGER)]
    private int $clicksCount = 0;

    #[ORM\Column(name: 'bounce_reason', type: Types::TEXT, nullable: true)]
    private ?string $bounceReason = null;

    #[ORM\Column(name: 'failure_reason', type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(name: 'attempt_count', type: Types::SMALLINT)]
    private int $attemptCount = 0;

    #[ORM\Column(name: 'claimed_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $claimedAt = null;

    #[ORM\Column(name: 'is_test', type: Types::BOOLEAN)]
    private bool $isTest = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getNewsletterId(): int
    {
        return $this->newsletterId;
    }

    public function setNewsletterId(int $v): self
    {
        $this->newsletterId = $v;

        return $this;
    }

    public function getContactId(): int
    {
        return $this->contactId;
    }

    public function setContactId(int $v): self
    {
        $this->contactId = $v;

        return $this;
    }

    public function getEmailAtSend(): string
    {
        return $this->emailAtSend;
    }

    public function setEmailAtSend(string $v): self
    {
        $this->emailAtSend = $v;

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

    public function getTrackingToken(): string
    {
        return $this->trackingToken;
    }

    public function setTrackingToken(string $v): self
    {
        $this->trackingToken = $v;

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

    public function getOpenedAt(): ?\DateTimeInterface
    {
        return $this->openedAt;
    }

    public function setOpenedAt(?\DateTimeInterface $v): self
    {
        $this->openedAt = $v;

        return $this;
    }

    public function getLastClickedAt(): ?\DateTimeInterface
    {
        return $this->lastClickedAt;
    }

    public function setLastClickedAt(?\DateTimeInterface $v): self
    {
        $this->lastClickedAt = $v;

        return $this;
    }

    public function getClicksCount(): int
    {
        return $this->clicksCount;
    }

    public function setClicksCount(int $v): self
    {
        $this->clicksCount = $v;

        return $this;
    }

    public function getBounceReason(): ?string
    {
        return $this->bounceReason;
    }

    public function setBounceReason(?string $v): self
    {
        $this->bounceReason = $v;

        return $this;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $v): self
    {
        $this->failureReason = $v;

        return $this;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function setAttemptCount(int $v): self
    {
        $this->attemptCount = $v;

        return $this;
    }

    public function getClaimedAt(): ?\DateTimeInterface
    {
        return $this->claimedAt;
    }

    public function setClaimedAt(?\DateTimeInterface $v): self
    {
        $this->claimedAt = $v;

        return $this;
    }

    public function isTest(): bool
    {
        return $this->isTest;
    }

    public function setIsTest(bool $v): self
    {
        $this->isTest = $v;

        return $this;
    }
}
