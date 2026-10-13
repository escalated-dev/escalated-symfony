<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures;

use Escalated\Symfony\Contract\TicketAccountResolverInterface;

final class TestAccountResolver implements TicketAccountResolverInterface
{
    public array $accounts = [];

    public function resolve(string $kind, ?string $type, int|string|null $id): ?array
    {
        return $this->accounts[$kind.':'.$id] ?? null;
    }
}
