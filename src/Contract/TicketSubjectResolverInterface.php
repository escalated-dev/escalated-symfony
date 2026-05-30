<?php

declare(strict_types=1);

namespace Escalated\Symfony\Contract;

/**
 * Resolves a stored subject type/id pair to a host model implementing
 * {@see TicketSubject} for API serialization.
 */
interface TicketSubjectResolverInterface
{
    public function resolve(string $type, string $id): ?TicketSubject;
}
