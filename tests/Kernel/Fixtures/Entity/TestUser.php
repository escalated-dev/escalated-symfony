<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The host application's user entity, as far as Escalated needs one: an
 * integer primary key (what `ESCALATED_USER_KEY_TYPE=int` stores) and an
 * identifier the security provider loads by.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_users')]
class TestUser implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 180, unique: true)]
        private string $email,
        #[ORM\Column(type: 'json')]
        private array $roles = [],
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
    }
}
