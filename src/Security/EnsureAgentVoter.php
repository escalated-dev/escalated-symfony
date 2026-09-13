<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\AgentProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Grants access when the current user has an AgentProfile record.
 *
 * An API token user additionally needs the agent (or admin / *) ability, and
 * its owner must still have an AgentProfile -- a token outlives the owner's
 * agent access otherwise, which the Laravel middleware also refuses.
 *
 * Usage in controllers:
 *   $this->denyAccessUnlessGranted('ESCALATED_AGENT');
 */
class EnsureAgentVoter extends Voter
{
    public const ATTRIBUTE = 'ESCALATED_AGENT';

    private readonly UserKeyResolver $userKeys;

    public function __construct(
        private readonly EntityManagerInterface $em,
        ?UserKeyResolver $userKeys = null,
    ) {
        $this->userKeys = $userKeys ?? new UserKeyResolver($em);
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

        if ($user instanceof ApiTokenUser && !in_array('ROLE_ESCALATED_AGENT', $user->getRoles(), true)) {
            return false;
        }

        // AgentProfile::userId is the host user's primary key. getUserIdentifier()
        // returns the identifier property (typically email) for session users,
        // and querying with it fails on strict-typed DBs (Postgres:
        // SQLSTATE[22P02] Invalid text representation).
        $userPk = $this->userKeys->resolve($user);
        if (null === $userPk) {
            return false;
        }

        $profile = $this->em->getRepository(AgentProfile::class)
            ->findOneBy(['userId' => $userPk]);

        return null !== $profile;
    }
}
