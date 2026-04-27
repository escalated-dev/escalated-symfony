<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Parses AWS SES inbound mail delivered via SNS HTTP subscription.
 * SES receipt rules publish to an SNS topic; host apps subscribe
 * via HTTP and SNS POSTs the envelope to the unified
 * {@code /escalated/webhook/email/inbound?adapter=ses} webhook.
 *
 * Handles two envelope types:
 *
 *   - {@code Type=SubscriptionConfirmation} — throws
 *     {@see SESSubscriptionConfirmationException} carrying the
 *     {@code SubscribeURL} that the host must GET out-of-band to
 *     activate the subscription.
 *   - {@code Type=Notification} — parses the JSON-encoded
 *     {@code Message} field for {@code mail.commonHeaders}
 *     (from/to/subject) and the {@code mail.headers} array
 *     (Message-ID / In-Reply-To / References). Falls back to
 *     {@code mail.headers} when {@code commonHeaders} doesn't
 *     surface a threading field.
 *
 * Best-effort MIME body extraction from the base64-encoded
 * {@code content} field when SES is configured with
 * {@code action.type=SNS} / {@code encoding=BASE64}. Hand-rolled
 * splitter (no external MIME dep) handles single-part
 * {@code text/plain}, {@code text/html}, {@code multipart/alternative},
 * and {@code quoted-printable} transfer encoding.
 */
final class SESInboundParser implements InboundEmailParser
{
    public function name(): string
    {
        return 'ses';
    }

    public function parse(array $rawPayload): InboundMessage
    {
        $type = (string) ($rawPayload['Type'] ?? '');

        match ($type) {
            'SubscriptionConfirmation' => throw new SESSubscriptionConfirmationException(topicArn: (string) ($rawPayload['TopicArn'] ?? ''), subscribeUrl: (string) ($rawPayload['SubscribeURL'] ?? ''), token: (string) ($rawPayload['Token'] ?? '')),
            'Notification' => null,
            default => throw new \InvalidArgumentException("Unsupported SNS envelope type: \"{$type}\""),
        };

        $messageJson = (string) ($rawPayload['Message'] ?? '');
        if ('' === $messageJson) {
            throw new \InvalidArgumentException('SES notification has no Message body');
        }

        $notification = json_decode($messageJson, true);
        if (!is_array($notification)) {
            throw new \InvalidArgumentException('SES notification Message is not valid JSON: '.json_last_error_msg());
        }

        $mail = is_array($notification['mail'] ?? null) ? $notification['mail'] : [];
        $common = is_array($mail['commonHeaders'] ?? null) ? $mail['commonHeaders'] : [];

        [$fromEmail, $fromName] = self::parseFirstAddressList($common['from'] ?? null);
        [$toEmail] = self::parseFirstAddressList($common['to'] ?? null);

        $subject = (string) ($common['subject'] ?? '');
        $headers = self::extractHeaders($mail);

        $messageId = self::blankToNull($common['messageId'] ?? null) ?? ($headers['Message-ID'] ?? null);
        $inReplyTo = self::blankToNull($common['inReplyTo'] ?? null) ?? ($headers['In-Reply-To'] ?? null);
        $references = self::blankToNull($common['references'] ?? null) ?? ($headers['References'] ?? null);

        [$bodyText, $bodyHtml] = self::extractBody((string) ($notification['content'] ?? ''));

        return new InboundMessage(
            fromEmail: $fromEmail,
            fromName: $fromName,
            toEmail: $toEmail,
            subject: $subject,
            bodyText: $bodyText,
            bodyHtml: $bodyHtml,
            messageId: $messageId,
            inReplyTo: $inReplyTo,
            references: $references,
            headers: $headers,
            attachments: [],
        );
    }

    /**
     * SES's {@code commonHeaders.from} / {@code .to} are arrays of
     * RFC 5322 strings. Returns the first usable entry's
     * {@code [email, display_name|null]}.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function parseFirstAddressList(mixed $list): array
    {
        if (!is_array($list) || [] === $list) {
            return ['', null];
        }
        foreach ($list as $entry) {
            if (!is_string($entry) || '' === trim($entry)) {
                continue;
            }
            $trimmed = trim($entry);
            if (1 === preg_match('/^\s*"?([^<"]*?)"?\s*<([^>]+)>\s*$/', $trimmed, $m)) {
                return [trim($m[2]), self::blankToNull(trim($m[1]))];
            }

            // Bare address.
            return [$trimmed, null];
        }

        return ['', null];
    }

    /**
     * Flatten {@code mail.headers} into a case-sensitive string map.
     * SES entries have shape {@code {"name":"X","value":"Y"}}.
     *
     * @return array<string, string>
     */
    private static function extractHeaders(array $mail): array
    {
        $out = [];
        $arr = $mail['headers'] ?? null;
        if (!is_array($arr)) {
            return $out;
        }
        foreach ($arr as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $entry['name'] ?? null;
            $value = $entry['value'] ?? null;
            if (is_string($name) && is_string($value) && '' !== $name) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * Decode the base64 {@code content} field and extract
     * {@code text/plain} + {@code text/html} parts. Returns
     * {@code [null, null]} when content is absent, malformed, or the
     * MIME parse fails.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function extractBody(string $contentBase64): array
    {
        if ('' === $contentBase64) {
            return [null, null];
        }
        $raw = base64_decode($contentBase64, true);
        if (false === $raw) {
            return [null, null];
        }

        $split = self::splitHeaders($raw);
        if (null === $split) {
            return [null, null];
        }
        [$headers, $body] = $split;

        $contentType = $headers['content-type'] ?? 'text/plain';
        $transferEnc = $headers['content-transfer-encoding'] ?? '7bit';

        $lowerCt = strtolower($contentType);
        if (str_starts_with($lowerCt, 'multipart/')) {
            return self::walkMultipart($body, $contentType);
        }
        if (str_starts_with($lowerCt, 'text/html')) {
            return [null, self::decodeBody($body, $transferEnc)];
        }

        return [self::decodeBody($body, $transferEnc), null];
    }

    /**
     * @return array{0: array<string, string>, 1: string}|null
     */
    private static function splitHeaders(string $raw): ?array
    {
        $pos = strpos($raw, "\r\n\r\n");
        $skip = 4;
        if (false === $pos) {
            $pos = strpos($raw, "\n\n");
            $skip = 2;
        }
        if (false === $pos) {
            return null;
        }
        $headerBlock = substr($raw, 0, $pos);
        $body = substr($raw, $pos + $skip);

        $headers = [];
        foreach (preg_split('/\r?\n/', $headerBlock) ?: [] as $line) {
            if ('' === $line) {
                continue;
            }
            $colon = strpos($line, ':');
            if (false === $colon || 0 === $colon) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $headers[$name] = $value;
        }

        return [$headers, $body];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function walkMultipart(string $body, string $contentType): array
    {
        if (1 !== preg_match('/boundary\s*=\s*"?([^";\s]+)"?/i', $contentType, $m)) {
            return [null, null];
        }
        $delimiter = '--'.$m[1];
        $parts = explode($delimiter, $body);
        // Drop preamble (before first delimiter) + closing epilogue.
        array_shift($parts);
        $text = null;
        $html = null;

        foreach ($parts as $part) {
            $trimmed = ltrim($part, "\r\n");
            if ('' === $trimmed || str_starts_with($trimmed, '--')) {
                continue;
            }
            $partSplit = self::splitHeaders($trimmed);
            if (null === $partSplit) {
                continue;
            }
            [$partHeaders, $partBody] = $partSplit;
            $partType = strtolower($partHeaders['content-type'] ?? '');
            $partEnc = $partHeaders['content-transfer-encoding'] ?? '7bit';
            $decoded = self::decodeBody(rtrim($partBody, "\r\n"), $partEnc);

            if (str_starts_with($partType, 'text/plain') && null === $text) {
                $text = $decoded;
            } elseif (str_starts_with($partType, 'text/html') && null === $html) {
                $html = $decoded;
            }
        }

        return [$text, $html];
    }

    private static function decodeBody(string $body, string $transferEnc): string
    {
        $enc = strtolower(trim($transferEnc));
        if ('quoted-printable' === $enc) {
            return quoted_printable_decode($body);
        }
        if ('base64' === $enc) {
            $decoded = base64_decode($body, true);

            return false === $decoded ? $body : $decoded;
        }

        return $body;
    }

    private static function blankToNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return '' === trim($value) ? null : $value;
    }
}
