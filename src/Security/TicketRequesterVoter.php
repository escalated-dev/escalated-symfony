<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Escalated\Symfony\Entity\Ticket;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Grants access when the current user is the ticket's requester.
 *
 * Usage in controllers:
 *   $this->isGranted('ESCALATED_TICKET_REQUESTER', $ticket);
 */
class TicketRequesterVoter extends Voter
{
    public const ATTRIBUTE = 'ESCALATED_TICKET_REQUESTER';

    public function __construct(
        private readonly UserKeyResolver $userKeys,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute && $subject instanceof Ticket;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Ticket);

        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            return false;
        }

        $requesterId = $subject->getRequesterId();
        if (null === $requesterId) {
            return false;
        }

        // A session user must also be of the requester's class, so user #7 of
        // one entity is not taken for requester #7 of another. A token user
        // carries only the owner's id.
        $requesterClass = $subject->getRequesterClass();
        if (null !== $requesterClass && !$user instanceof ApiTokenUser && !is_a($user, $requesterClass)) {
            return false;
        }

        $userKey = $this->userKeys->resolve($user);

        return null !== $userKey && (string) $userKey === (string) $requesterId;
    }
}
