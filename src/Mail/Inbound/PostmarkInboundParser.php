<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Parses Postmark's inbound webhook payload into an
 * {@see InboundMessage}. Postmark POSTs a JSON body with
 * {@code FromFull} / {@code ToFull} / {@code Subject} / {@code TextBody}
 * / {@code HtmlBody} / {@code Headers} / {@code Attachments} fields.
 *
 * <p>Additional providers (Mailgun, SES) register by implementing
 * {@link InboundEmailParser} as services tagged with
 * {@code escalated.inbound_parser} — the controller selects by
 * {@link name()}.
 */
final class PostmarkInboundParser implements InboundEmailParser
{
    public function name(): string
    {
        return 'postmark';
    }

    public function parse(array $rawPayload): InboundMessage
    {
        $fromFull = is_array($rawPayload['FromFull'] ?? null) ? $rawPayload['FromFull'] : [];
        $fromEmail = (string) ($fromFull['Email'] ?? $rawPayload['From'] ?? '');
        $fromName = $fromFull['Name'] ?? $rawPayload['FromName'] ?? null;

        $toEmail = (string) (
            $rawPayload['OriginalRecipient']
            ?? self::firstToEmail($rawPayload)
            ?? $rawPayload['To']
            ?? ''
        );

        $headers = self::extractHeaders($rawPayload);

        return new InboundMessage(
            fromEmail: $fromEmail,
            fromName: self::blankToNull($fromName),
            toEmail: $toEmail,
            subject: (string) ($rawPayload['Subject'] ?? ''),
            bodyText: self::blankToNull($rawPayload['TextBody'] ?? null),
            bodyHtml: self::blankToNull($rawPayload['HtmlBody'] ?? null),
            messageId: self::firstNonEmpty(
                $rawPayload['MessageID'] ?? null,
                $headers['Message-ID'] ?? null
            ),
            inReplyTo: $headers['In-Reply-To'] ?? null,
            references: $headers['References'] ?? null,
            headers: $headers,
            attachments: self::extractAttachments($rawPayload),
        );
    }

    private static function firstToEmail(array $payload): ?string
    {
        $toFull = $payload['ToFull'] ?? null;
        if (!is_array($toFull)) {
            return null;
        }
        foreach ($toFull as $entry) {
            if (is_array($entry)) {
                $email = $entry['Email'] ?? null;
                if (is_string($email) && '' !== $email) {
                    return $email;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private static function extractHeaders(array $payload): array
    {
        $out = [];
        $arr = $payload['Headers'] ?? null;
        if (!is_array($arr)) {
            return $out;
        }
        foreach ($arr as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $entry['Name'] ?? null;
            $value = $entry['Value'] ?? null;
            if (is_string($name) && '' !== $name && is_string($value)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * @return list<InboundAttachment>
     */
    private static function extractAttachments(array $payload): array
    {
        $list = [];
        $arr = $payload['Attachments'] ?? null;
        if (!is_array($arr)) {
            return $list;
        }
        foreach ($arr as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $content = null;
            if (isset($entry['Content']) && is_string($entry['Content']) && '' !== $entry['Content']) {
                $decoded = base64_decode($entry['Content'], true);
                if (false !== $decoded) {
                    $content = $decoded;
                }
            }
            $list[] = new InboundAttachment(
                name: (string) ($entry['Name'] ?? 'attachment'),
                contentType: (string) ($entry['ContentType'] ?? 'application/octet-stream'),
                sizeBytes: isset($entry['ContentLength']) && is_numeric($entry['ContentLength'])
                    ? (int) $entry['ContentLength']
                    : null,
                content: $content,
                downloadUrl: isset($entry['ContentURL']) && is_string($entry['ContentURL'])
                    ? $entry['ContentURL']
                    : null,
            );
        }

        return $list;
    }

    private static function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    private static function blankToNull(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
