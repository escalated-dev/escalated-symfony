<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Lightweight security identity created from a validated API token. It is not
 * a Doctrine entity or a service — the ApiTokenAuthenticator instantiates it
 * directly and places it on the token storage so that `$this->getUser()` and
 * the ROLE_* voters see the token's abilities as roles.
 *
 * Abilities map to roles by ApiTokenAuthenticator:
 *   admin / *  -> ROLE_ESCALATED_ADMIN (+ ROLE_ESCALATED_AGENT)
 *   agent / *  -> ROLE_ESCALATED_AGENT
 */
final class ApiTokenUser implements UserInterface
{
    /**
     * @param string[] $roles
     */
    public function __construct(
        private readonly string $identifier,
        private readonly array $roles = [],
    ) {
    }

    /**
     * @return string[]
     */
    public function getRoles(): array
    {
        return array_values(array_unique(array_merge($this->roles, ['ROLE_USER'])));
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * Required by UserInterface on Symfony 6.4 / 7.0-7.2 (deprecated and removed
     * from the interface in 7.3). API tokens carry no credentials to erase.
     */
    public function eraseCredentials(): void
    {
    }
}
