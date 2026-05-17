<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Repository\AgentSkillRepository;

#[ORM\Entity(repositoryClass: AgentSkillRepository::class)]
#[ORM\Table(name: 'escalated_agent_skills')]
#[ORM\UniqueConstraint(name: 'UNIQ_agent_skills_user_skill', columns: ['user_id', 'skill_id'])]
#[ORM\HasLifecycleCallbacks]
class AgentSkill
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $userId;

    #[ORM\ManyToOne(targetEntity: Skill::class, inversedBy: 'agentSkills')]
    #[ORM\JoinColumn(name: 'skill_id', nullable: false, onDelete: 'CASCADE')]
    private ?Skill $skill = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $proficiency = 3;

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

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getSkill(): ?Skill
    {
        return $this->skill;
    }

    public function setSkill(?Skill $skill): self
    {
        $this->skill = $skill;

        return $this;
    }

    public function getProficiency(): int
    {
        return $this->proficiency;
    }

    public function setProficiency(int $proficiency): self
    {
        $this->proficiency = $proficiency;

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
