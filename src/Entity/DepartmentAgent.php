<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Doctrine\UserIdType;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_department_agent')]
class DepartmentAgent
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Department::class)]
        #[ORM\JoinColumn(name: 'department_id', nullable: false, onDelete: 'CASCADE')]
        private Department $department,
        #[ORM\Id]
        #[ORM\Column(name: 'agent_id', type: UserIdType::NAME)]
        private int|string $agentId,
    ) {
    }

    public function getDepartment(): Department
    {
        return $this->department;
    }

    public function getAgentId(): int|string
    {
        return $this->agentId;
    }
}
