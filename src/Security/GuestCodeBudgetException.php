<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

/**
 * A mailbox is over its hourly budget of verification codes.
 */
final class GuestCodeBudgetException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Please wait before requesting another code.');
    }
}
