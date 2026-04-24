<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Parses Mailgun's inbound webhook payload into an
 * {@see InboundMessage}.
 *
 * <p>Mailgun POSTs {@code multipart/form-data} with snake-case field
 * names: {@code sender}, {@code recipient}, {@code subject},
 * {@code body-plain}, {@code body-html}, {@code Message-Id},
 * {@code In-Reply-To}, {@code References}, plus a JSON-encoded
 * {@code attachments} field.
 *
 * <p>Notes:
 * <ul>
 *   <li>Mailgun's {@code from} is typically
 *       {@code "Full Name <email@host>"} — extracts the display name
 *       portion separately and falls back to the {@code sender} field
 *       for the email. Strips surrounding quotes on the display name.</li>
 *   <li>Mailgun hosts attachment content behind a URL (large
 *       attachments); we carry the URL through in
 *       {@link InboundAttachment::$downloadUrl}.</li>
 *   <li>Malformed {@code attachments} JSON degrades gracefully
 *       (empty list).</li>
 * </ul>
 */
final class MailgunInboundParser implements InboundEmailParser
{
    public function name(): string
    {
        return 'mailgun';
    }

    public function parse(array $rawPayload): InboundMessage
    {
        $fromEmail = self::field($rawPayload, 'sender') ?? self::field($rawPayload, 'from') ?? '';
        $fromName = self::extractFromName(self::field($rawPayload, 'from'));

        $toEmail = self::field($rawPayload, 'recipient') ?? self::field($rawPayload, 'To') ?? '';

        $messageId = self::field($rawPayload, 'Message-Id');
        $inReplyTo = self::field($rawPayload, 'In-Reply-To');
        $references = self::field($rawPayload, 'References');

        $headers = array_filter(
            [
                'Message-ID' => $messageId,
                'In-Reply-To' => $inReplyTo,
                'References' => $references,
            ],
            static fn ($v) => is_string($v) && $v !== '',
        );

        return new InboundMessage(
            fromEmail: (string) $fromEmail,
            fromName: self::blankToNull($fromName),
            toEmail: (string) $toEmail,
            subject: (string) (self::field($rawPayload, 'subject') ?? ''),
            bodyText: self::blankToNull(self::field($rawPayload, 'body-plain')),
            bodyHtml: self::blankToNull(self::field($rawPayload, 'body-html')),
            messageId: self::blankToNull($messageId),
            inReplyTo: self::blankToNull($inReplyTo),
            references: self::blankToNull($references),
            headers: $headers,
            attachments: self::parseAttachments(self::field($rawPayload, 'attachments')),
        );
    }

    private static function field(array $payload, string $key): ?string
    {
        $v = $payload[$key] ?? $payload[strtolower($key)] ?? null;

        return is_string($v) ? $v : (is_scalar($v) ? (string) $v : null);
    }

    private static function extractFromName(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $angle = strpos($raw, '<');
        if ($angle === false || $angle === 0) {
            return null;
        }
        $name = trim(substr($raw, 0, $angle));
        if ($name === '') {
            return null;
        }
        // Strip surrounding quotes if present.
        if (strlen($name) >= 2 && str_starts_with($name, '"') && str_ends_with($name, '"')) {
            $name = substr($name, 1, -1);
        }

        return $name === '' ? null : $name;
    }

    /**
     * @return list<InboundAttachment>
     */
    private static function parseAttachments(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }
        $list = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $size = $entry['size'] ?? null;
            $list[] = new InboundAttachment(
                name: (string) ($entry['name'] ?? 'attachment'),
                contentType: (string) ($entry['content-type'] ?? 'application/octet-stream'),
                sizeBytes: is_numeric($size) ? (int) $size : null,
                content: null,
                downloadUrl: isset($entry['url']) && is_string($entry['url']) ? $entry['url'] : null,
            );
        }

        return $list;
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
