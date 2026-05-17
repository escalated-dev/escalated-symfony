<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Repository\SkillRepository;

#[ORM\Entity(repositoryClass: SkillRepository::class)]
#[ORM\Table(name: 'escalated_skills')]
#[ORM\UniqueConstraint(name: 'UNIQ_escalated_skills_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'UNIQ_escalated_skills_name', columns: ['name'])]
#[ORM\HasLifecycleCallbacks]
class Skill
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name = '';

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, SkillRoutingTag> */
    #[ORM\OneToMany(targetEntity: SkillRoutingTag::class, mappedBy: 'skill', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $routingTags;

    /** @var Collection<int, SkillRoutingDepartment> */
    #[ORM\OneToMany(targetEntity: SkillRoutingDepartment::class, mappedBy: 'skill', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $routingDepartments;

    /** @var Collection<int, AgentSkill> */
    #[ORM\OneToMany(targetEntity: AgentSkill::class, mappedBy: 'skill', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $agentSkills;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->routingTags = new ArrayCollection();
        $this->routingDepartments = new ArrayCollection();
        $this->agentSkills = new ArrayCollection();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** @return Collection<int, SkillRoutingTag> */
    public function getRoutingTags(): Collection
    {
        return $this->routingTags;
    }

    public function addRoutingTag(SkillRoutingTag $row): self
    {
        if (!$this->routingTags->contains($row)) {
            $this->routingTags->add($row);
            $row->setSkill($this);
        }

        return $this;
    }

    public function removeRoutingTag(SkillRoutingTag $row): self
    {
        $this->routingTags->removeElement($row);

        return $this;
    }

    /** @return Collection<int, SkillRoutingDepartment> */
    public function getRoutingDepartments(): Collection
    {
        return $this->routingDepartments;
    }

    public function addRoutingDepartment(SkillRoutingDepartment $row): self
    {
        if (!$this->routingDepartments->contains($row)) {
            $this->routingDepartments->add($row);
            $row->setSkill($this);
        }

        return $this;
    }

    public function removeRoutingDepartment(SkillRoutingDepartment $row): self
    {
        $this->routingDepartments->removeElement($row);

        return $this;
    }

    /** @return Collection<int, AgentSkill> */
    public function getAgentSkills(): Collection
    {
        return $this->agentSkills;
    }

    public function addAgentSkill(AgentSkill $row): self
    {
        if (!$this->agentSkills->contains($row)) {
            $this->agentSkills->add($row);
            $row->setSkill($this);
        }

        return $this;
    }

    public function removeAgentSkill(AgentSkill $row): self
    {
        $this->agentSkills->removeElement($row);

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
