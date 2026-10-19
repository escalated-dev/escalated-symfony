<?php

declare(strict_types=1);

namespace Escalated\Symfony\Widget;

use Escalated\Symfony\Service\SettingsService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decides which other sites may embed the widget.
 *
 * The allow list is the `allowedOrigins` argument of {@see WidgetSettings}
 * (host service configuration, for example
 * `%env(csv:ESCALATED_WIDGET_ALLOWED_ORIGINS)%`) plus the admin setting
 * `widget_allowed_origins` (one origin per line or comma separated). An empty
 * list allows no other site.
 *
 * Entries are bare origins, `scheme://host[:port]` over http or https.
 * `https://*.example.com` matches every subdomain of example.com, at any
 * depth, but not example.com itself. A bare `*` is rejected.
 */
class WidgetOrigins
{
    /** Request attribute holding the Origin of an allowed cross-site request. */
    public const ATTRIBUTE = '_escalated_widget_origin';

    /** Key of the admin setting in the escalated_settings store. */
    public const SETTING = 'widget_allowed_origins';

    public function __construct(
        private readonly WidgetSettings $widgetSettings,
        private readonly SettingsService $settings,
    ) {
    }

    public function allows(string $origin): bool
    {
        // The configured list first, so a listed origin never reads the database.
        return self::matchesAny(self::patterns($this->widgetSettings->getAllowedOrigins()), $origin)
            || self::matchesAny(self::patterns(self::split((string) $this->settings->get(self::SETTING, ''))), $origin);
    }

    /**
     * The combined allow list, normalised, without duplicates or invalid entries.
     *
     * @return list<string>
     */
    public function configured(): array
    {
        return self::patterns([
            ...$this->widgetSettings->getAllowedOrigins(),
            ...self::split((string) $this->settings->get(self::SETTING, '')),
        ]);
    }

    /**
     * The request's Origin when it names another site, or null for same-origin
     * requests, opaque (`null`) origins and requests without an Origin header.
     */
    public static function crossOrigin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if (!\is_string($origin) || '' === $origin || 'null' === $origin) {
            return null;
        }

        $origin = self::normalize($origin);
        if (null === $origin || $origin === self::normalize($request->getSchemeAndHttpHost())) {
            return null;
        }

        return $origin;
    }

    /**
     * Normalise an origin or `*.` pattern, or null when the entry is not a bare
     * http(s) origin.
     */
    public static function normalizePattern(string $entry): ?string
    {
        if (!preg_match('#^(https?)://(\*\.)?([^/?\#*]+?)/?$#i', trim($entry), $m)) {
            return null;
        }

        $host = self::normalize(strtolower($m[1]).'://'.$m[3]);
        if (null === $host) {
            return null;
        }

        return '' !== $m[2] ? str_replace('://', '://*.', $host) : $host;
    }

    /**
     * Entries of an admin-entered list that are not valid origins.
     *
     * @return list<string>
     */
    public static function invalidEntries(string $value): array
    {
        return array_values(array_filter(
            self::split($value),
            static fn (string $entry): bool => null === self::normalizePattern($entry),
        ));
    }

    /**
     * @return list<string>
     */
    public static function split(string $value): array
    {
        return array_values(array_filter(
            preg_split('/[\s,]+/', $value) ?: [],
            static fn (string $entry): bool => '' !== $entry,
        ));
    }

    /**
     * @param array<mixed> $entries
     *
     * @return list<string>
     */
    public static function patterns(array $entries): array
    {
        $patterns = [];
        foreach ($entries as $entry) {
            $pattern = \is_scalar($entry) ? self::normalizePattern((string) $entry) : null;
            if (null !== $pattern) {
                $patterns[$pattern] = true;
            }
        }

        return array_keys($patterns);
    }

    /**
     * @param list<string> $patterns
     */
    public static function matchesAny(array $patterns, string $origin): bool
    {
        $origin = self::normalize($origin);
        if (null === $origin) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $pattern, string $origin): bool
    {
        if (!str_contains($pattern, '://*.')) {
            return $pattern === $origin;
        }

        [$scheme, $suffix] = explode('://*.', $pattern, 2);
        if (!str_starts_with($origin, $scheme.'://')) {
            return false;
        }
        $host = substr($origin, \strlen($scheme) + 3);

        return str_ends_with($host, '.'.$suffix) && \strlen($host) > \strlen($suffix) + 1;
    }

    /** scheme://host[:port], lowercased, default ports dropped. */
    private static function normalize(string $origin): ?string
    {
        $parts = parse_url(trim($origin));
        if (false === $parts) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host
            || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
            || ('' !== $path && '/' !== $path)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if (null === $port || ('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port)) {
            return $scheme.'://'.$host;
        }

        return $scheme.'://'.$host.':'.$port;
    }
}
