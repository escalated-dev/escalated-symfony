<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures\Embed;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The host's own admin gate, the way a host writes one: a voter for the
 * `HOST_ADMIN` attribute, granted to users with ROLE_HOST_ADMIN. Used as an
 * embed guard. It marks the request it decided on, and
 * {@see HostHeadersSubscriber} turns that into an `X-Host-Guard` header, so a
 * test sees over HTTP that the guard ran.
 */
final class HostAdminVoter extends Voter
{
    public const ATTRIBUTE = 'HOST_ADMIN';
    public const ROLE = 'ROLE_HOST_ADMIN';
    public const MARK = '_host_guard';

    public function __construct(private readonly RequestStack $requests)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $granted = $user instanceof UserInterface && \in_array(self::ROLE, $user->getRoles(), true);
        $this->requests->getMainRequest()?->attributes->set(self::MARK, $granted ? 'granted' : 'denied');

        return $granted;
    }
}
