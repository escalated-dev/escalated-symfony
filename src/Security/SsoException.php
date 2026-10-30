<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

/**
 * A refused SSO sign-in. The message says why, for the log only: the user is
 * shown a generic error.
 */
final class SsoException extends \RuntimeException
{
}
