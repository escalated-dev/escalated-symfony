<?php

declare(strict_types=1);

namespace Escalated\Symfony\Contract;

interface TicketAccountResolverInterface
{
    /** Return a complete ['type' => string, 'id' => string|int] pair or null. Kind is requester, subject, or agent. */
    public function resolve(string $kind, ?string $type, int|string|null $id): ?array;
}
