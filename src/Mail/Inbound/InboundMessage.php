<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Transport-agnostic representation of an inbound email, independent
 * of the source adapter (Postmark, Mailgun, SES, IMAP, etc.).
 *
 * Adapters normalize their provider-specific webhook payload into
 * this shape; {@see InboundRouter} then maps it to an existing
 * ticket via canonical Message-ID parsing + signed Reply-To
 * verification.
 */
final class InboundMessage
{
    /**
     * @param array<string,string>  $headers
     * @param list<InboundAttachment>  $attachments
     */
    public function __construct(
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly string $toEmail,
        public readonly string $subject,
        public readonly ?string $bodyText = null,
        public readonly ?string $bodyHtml = null,
        public readonly ?string $messageId = null,
        public readonly ?string $inReplyTo = null,
        public readonly ?string $references = null,
        public readonly array $headers = [],
        public readonly array $attachments = [],
    ) {
    }

    /**
     * Best body content — plain text preferred, HTML fallback.
     */
    public function body(): string
    {
        if ($this->bodyText !== null && $this->bodyText !== '') {
            return $this->bodyText;
        }

        return $this->bodyHtml ?? '';
    }
}
