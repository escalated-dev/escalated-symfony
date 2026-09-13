<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Transport-specific parser that normalizes a provider's webhook
 * payload into an {@see InboundMessage}. Implementations register as
 * Symfony services tagged with `escalated.inbound_parser` (the attribute
 * below tags every autoconfigured implementation) and
 * InboundEmailController picks the matching parser by {@see name()}.
 *
 * Add a new provider by implementing this interface in an autoconfigured
 * service; it is tagged and offered to the controller automatically.
 */
#[AutoconfigureTag('escalated.inbound_parser')]
interface InboundEmailParser
{
    /**
     * Short provider name. Must match the adapter label on the
     * inbound webhook request (e.g. `?adapter=postmark` or
     * `X-Escalated-Adapter: postmark`).
     */
    public function name(): string;

    /**
     * Parse a raw webhook payload (associative array already decoded
     * from JSON) into an InboundMessage.
     */
    public function parse(array $rawPayload): InboundMessage;
}
