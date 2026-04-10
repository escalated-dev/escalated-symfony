<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_email_channels')]
#[ORM\HasLifecycleCallbacks]
class EmailChannel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $emailAddress = '';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $displayName = null;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?Department $department = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isVerified = false;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $dkimStatus = 'pending';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dkimPublicKey = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $dkimSelector = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $replyToAddress = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $smtpProtocol = 'tls';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $smtpHost = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $smtpPort = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $smtpUsername = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $smtpPassword = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

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

    public function getEmailAddress(): string
    {
        return $this->emailAddress;
    }

    public function setEmailAddress(string $emailAddress): self
    {
        $this->emailAddress = $emailAddress;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): self
    {
        $this->department = $department;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): self
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getDkimStatus(): string
    {
        return $this->dkimStatus;
    }

    public function setDkimStatus(string $dkimStatus): self
    {
        $this->dkimStatus = $dkimStatus;

        return $this;
    }

    public function getDkimPublicKey(): ?string
    {
        return $this->dkimPublicKey;
    }

    public function setDkimPublicKey(?string $dkimPublicKey): self
    {
        $this->dkimPublicKey = $dkimPublicKey;

        return $this;
    }

    public function getDkimSelector(): ?string
    {
        return $this->dkimSelector;
    }

    public function setDkimSelector(?string $dkimSelector): self
    {
        $this->dkimSelector = $dkimSelector;

        return $this;
    }

    public function getReplyToAddress(): ?string
    {
        return $this->replyToAddress;
    }

    public function setReplyToAddress(?string $replyToAddress): self
    {
        $this->replyToAddress = $replyToAddress;

        return $this;
    }

    public function getSmtpProtocol(): string
    {
        return $this->smtpProtocol;
    }

    public function setSmtpProtocol(string $smtpProtocol): self
    {
        $this->smtpProtocol = $smtpProtocol;

        return $this;
    }

    public function getSmtpHost(): ?string
    {
        return $this->smtpHost;
    }

    public function setSmtpHost(?string $smtpHost): self
    {
        $this->smtpHost = $smtpHost;

        return $this;
    }

    public function getSmtpPort(): ?int
    {
        return $this->smtpPort;
    }

    public function setSmtpPort(?int $smtpPort): self
    {
        $this->smtpPort = $smtpPort;

        return $this;
    }

    public function getSmtpUsername(): ?string
    {
        return $this->smtpUsername;
    }

    public function setSmtpUsername(?string $smtpUsername): self
    {
        $this->smtpUsername = $smtpUsername;

        return $this;
    }

    public function getSmtpPassword(): ?string
    {
        return $this->smtpPassword;
    }

    public function setSmtpPassword(?string $smtpPassword): self
    {
        $this->smtpPassword = $smtpPassword;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

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

    public function getFormattedSender(): string
    {
        if ($this->displayName) {
            return sprintf('%s <%s>', $this->displayName, $this->emailAddress);
        }

        return $this->emailAddress;
    }
}
