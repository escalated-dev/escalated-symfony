<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Repository\SkillRoutingDepartmentRepository;

#[ORM\Entity(repositoryClass: SkillRoutingDepartmentRepository::class)]
#[ORM\Table(name: 'escalated_skill_routing_departments')]
#[ORM\UniqueConstraint(name: 'UNIQ_skill_routing_dept', columns: ['skill_id', 'department_id'])]
class SkillRoutingDepartment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Skill::class, inversedBy: 'routingDepartments')]
    #[ORM\JoinColumn(name: 'skill_id', nullable: false, onDelete: 'CASCADE')]
    private ?Skill $skill = null;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', nullable: false, onDelete: 'CASCADE')]
    private ?Department $department = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): self
    {
        $this->department = $department;

        return $this;
    }
}
