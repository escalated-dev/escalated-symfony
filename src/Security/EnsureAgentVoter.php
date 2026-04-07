<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\AgentProfile;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
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

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            return false;
        }

        // Check if the user has an agent profile
        $profile = $this->em->getRepository(AgentProfile::class)
            ->findOneBy(['userId' => $user->getUserIdentifier()]);

        return null !== $profile;
    }
}
