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
 * Usage in controllers:
 *   $this->denyAccessUnlessGranted('ESCALATED_AGENT');
 */
class EnsureAgentVoter extends Voter
{
    public const ATTRIBUTE = 'ESCALATED_AGENT';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
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

        // AgentProfile::userId is an integer FK into the configured user entity.
        // getUserIdentifier() returns the identifier property (typically email),
        // not the primary key — querying with it fails on strict-typed DBs
        // (Postgres: SQLSTATE[22P02] Invalid text representation).
        $userPk = $this->resolveUserPrimaryKey($user);
        if (null === $userPk) {
            return false;
        }

        $profile = $this->em->getRepository(AgentProfile::class)
            ->findOneBy(['userId' => $userPk]);

        return null !== $profile;
    }

    private function resolveUserPrimaryKey(UserInterface $user): ?int
    {
        if (!$this->em->getMetadataFactory()->hasMetadataFor($user::class)) {
            return null;
        }

        $idValues = $this->em->getClassMetadata($user::class)->getIdentifierValues($user);
        $id = reset($idValues);

        return is_numeric($id) ? (int) $id : null;
    }
}
