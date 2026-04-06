<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Grants access when the current user has the ROLE_ESCALATED_ADMIN role.
 *
 * Usage in controllers:
 *   $this->denyAccessUnlessGranted('ESCALATED_ADMIN');
 */
class EnsureAdminVoter extends Voter
{
    public const ATTRIBUTE = 'ESCALATED_ADMIN';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            return false;
        }

        return in_array('ROLE_ESCALATED_ADMIN', $user->getRoles(), true);
    }
}
