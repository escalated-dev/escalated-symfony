<?php

declare(strict_types=1);

namespace Escalated\Symfony\Contract;

use Symfony\Component\Security\Core\User\UserInterface;

interface TicketAccessUserResolverInterface
{
    /** Reload the current host identity, including its current roles; null denies access. */
    public function resolve(int|string $id): ?UserInterface;
}
