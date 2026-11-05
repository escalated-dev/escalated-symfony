<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Escalated\Symfony\Embed\EmbedConfig;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Grants access when the current user has the ROLE_ESCALATED_ADMIN role.
 *
 * While the admin is embedded in the host's admin panel with
 * `ui.embed.replace_admin_gate` (ADR 2026-11-05-embeddable-admin), it also
 * grants any signed-in user on an embedded admin route: the host's guards,
 * which have already run on that route, decide who reaches it. Nowhere else.
 *
 * Usage in controllers:
 *   $this->denyAccessUnlessGranted('ESCALATED_ADMIN');
 */
class EnsureAdminVoter extends Voter
{
    public const ATTRIBUTE = 'ESCALATED_ADMIN';

    public const ROLE = 'ROLE_ESCALATED_ADMIN';

    public function __construct(
        private readonly ?RequestStack $requests = null,
        private readonly ?EmbedConfig $embed = null,
    ) {
    }

    /** Escalated's own admin gate, whatever the request. */
    public static function hasAdminRole(?UserInterface $user): bool
    {
        return $user instanceof UserInterface && \in_array(self::ROLE, $user->getRoles(), true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            return false;
        }

        if (self::hasAdminRole($user)) {
            return true;
        }

        return !$user instanceof ApiTokenUser
            && null !== $this->embed
            && $this->embed->replacesAdminGateFor($this->requests?->getMainRequest());
    }
}
