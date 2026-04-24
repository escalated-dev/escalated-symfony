<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Transport-specific parser that normalizes a provider's webhook
 * payload into an {@see InboundMessage}. Implementations register as
 * Symfony services tagged with `escalated.inbound_parser` and the
 * controller (in a follow-up PR) picks the matching parser by
 * {@see name()}.
 *
 * Add a new provider by implementing this interface; the DI tag will
 * wire it up automatically.
 */
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
