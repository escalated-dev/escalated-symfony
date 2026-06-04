<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

class NewsletterPermissionVoter extends Voter
{
    private const ROLE_MAP = [
        NewsletterPermissions::MANAGE => 'ROLE_ESCALATED_NEWSLETTERS_MANAGE',
        NewsletterPermissions::SEND => 'ROLE_ESCALATED_NEWSLETTERS_SEND',
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return isset(self::ROLE_MAP[$attribute]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            return false;
        }

        $roles = $user->getRoles();

        return in_array('ROLE_ESCALATED_ADMIN', $roles, true)
            || in_array(self::ROLE_MAP[$attribute], $roles, true);
    }
}
