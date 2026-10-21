<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

/**
 * A guest verification request or code was rejected. `field` names the
 * request field the message belongs to, for a 422 `errors` object.
 */
final class GuestVerificationException extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
