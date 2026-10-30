<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Decides `newsletters.manage` and `newsletters.send`
 * (ADR 2026-10-29-configurable-access-features):
 *
 *  - roles & permissions off: the admin gate alone (ROLE_ESCALATED_ADMIN),
 *    like every other admin screen;
 *  - on: the admin gate and the permission from the user's Escalated roles.
 */
class NewsletterPermissionVoter extends Voter
{
    private const SLUGS = [NewsletterPermissions::MANAGE, NewsletterPermissions::SEND];

    public function __construct(
        private readonly AccessFeatures $features = new AccessFeatures(),
        private readonly ?UserPermissions $permissions = null,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::SLUGS, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface || !\in_array('ROLE_ESCALATED_ADMIN', $user->getRoles(), true)) {
            return false;
        }

        if (!$this->features->rolesPermissions()) {
            return true;
        }

        return null !== $this->permissions && $this->permissions->holds($user, $attribute);
    }
}
