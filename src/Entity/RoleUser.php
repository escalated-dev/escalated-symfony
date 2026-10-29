<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Doctrine\UserIdType;

/**
 * A host user holding an Escalated role (escalated-laravel's `role_user`
 * pivot). The user is the host's, so it is stored by key, not related.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_role_user')]
class RoleUser
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: UserIdType::NAME)]
        private int|string $userId,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Role::class)]
        #[ORM\JoinColumn(name: 'role_id', nullable: false, onDelete: 'CASCADE')]
        private Role $role,
    ) {
    }

    public function getUserId(): int|string
    {
        return $this->userId;
    }

    public function getRole(): Role
    {
        return $this->role;
    }
}
