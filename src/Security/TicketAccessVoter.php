<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Escalated\Symfony\Entity\Ticket;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

final class TicketAccessVoter extends Voter
{
    public function __construct(private readonly TicketAccess $access, private readonly AuthorizationCheckerInterface $authorization)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Ticket && in_array($attribute, ['ESCALATED_TICKET_VIEW', 'ESCALATED_TICKET_UPDATE', 'ESCALATED_TICKET_REPLY', 'ESCALATED_TICKET_ASSIGN', 'ESCALATED_TICKET_CLOSE'], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            return false;
        }
        if (!$this->authorization->isGranted('ESCALATED_AGENT') && !$this->authorization->isGranted('ESCALATED_ADMIN')) {
            return false;
        }

        return $this->access->runAs($user, fn () => $this->access->visibleEntity($subject));
    }
}
