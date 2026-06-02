<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity\Newsletter;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Doctrine\UserIdType;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_newsletter_list_members')]
class NewsletterListMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'list_id', type: Types::INTEGER)]
    private int $listId;

    #[ORM\Column(name: 'contact_id', type: Types::INTEGER)]
    private int $contactId;

    #[ORM\Column(name: 'added_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $addedAt;

    #[ORM\Column(name: 'added_by', type: UserIdType::NAME, nullable: true)]
    private int|string|null $addedBy = null;

    public function __construct()
    {
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getListId(): int
    {
        return $this->listId;
    }

    public function setListId(int $v): self
    {
        $this->listId = $v;

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

    public function getAddedAt(): \DateTimeInterface
    {
        return $this->addedAt;
    }

    public function getAddedBy(): ?string
    {
        return $this->addedBy;
    }

    public function setAddedBy(?string $v): self
    {
        $this->addedBy = $v;

        return $this;
    }
}
