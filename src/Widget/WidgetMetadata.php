<?php

declare(strict_types=1);

namespace Escalated\Symfony\Widget;

/**
 * Host parameters an embedding page sends with a widget ticket or chat as
 * `metadata` (order number, plan, account id, the page URL, ...). They come
 * from the visitor's browser, so only a flat map of short scalars is kept.
 */
final class WidgetMetadata
{
    public const MAX_ENTRIES = 25;

    public const MAX_KEYS = 50;

    public const MAX_VALUE_LENGTH = 500;

    public const MAX_URL_LENGTH = 2048;

    /**
     * Whether a request's `metadata` may be accepted: absent, or a JSON object
     * of at most 50 keys.
     */
    public static function isValid(mixed $metadata): bool
    {
        if (null === $metadata) {
            return true;
        }

        return \is_array($metadata)
            && ([] === $metadata || !array_is_list($metadata))
            && \count($metadata) <= self::MAX_KEYS;
    }

    /**
     * The entries worth keeping, plus `embedded_on` from the request's allowed
     * cross-site Origin (never from the page). Null when nothing is left.
     *
     * @return array<string, string|int|float|bool>|null
     */
    public static function sanitize(mixed $metadata, ?string $embeddedOn): ?array
    {
        $clean = [];
        foreach (\is_array($metadata) ? $metadata : [] as $key => $value) {
            if (\count($clean) >= self::MAX_ENTRIES) {
                break;
            }
            if (!\is_string($key) || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $key)) {
                continue;
            }
            if (\is_string($value)) {
                $value = mb_substr(trim($value), 0, self::MAX_VALUE_LENGTH);
            } elseif (!\is_int($value) && !\is_float($value) && !\is_bool($value)) {
                continue;
            }
            $clean[$key] = $value;
        }

        if (isset($clean['page_url']) && !self::isWebUrl($clean['page_url'])) {
            unset($clean['page_url']);
        }

        // Recorded by the server from the request's Origin header, so the page
        // cannot claim another site.
        unset($clean['embedded_on']);
        if (null !== $embeddedOn) {
            $clean['embedded_on'] = $embeddedOn;
        }

        return [] === $clean ? null : $clean;
    }

    public static function isWebUrl(mixed $value): bool
    {
        return \is_string($value) && \strlen($value) <= self::MAX_URL_LENGTH
            && false !== filter_var($value, \FILTER_VALIDATE_URL)
            && \in_array(strtolower((string) parse_url($value, \PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
