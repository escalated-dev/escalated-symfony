<?php

declare(strict_types=1);

namespace Escalated\Symfony\Http;

/**
 * Raised when a webhook request cannot be completed at the transport level
 * (DNS resolution failure, connection refused, timeout, ...). Distinct from a
 * non-2xx HTTP response, which is returned normally and recorded as a failed
 * delivery.
 */
class WebhookTransportException extends \RuntimeException
{
}
