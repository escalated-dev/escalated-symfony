<?php

declare(strict_types=1);

namespace Escalated\Symfony\Embed;

use Symfony\Component\HttpFoundation\Request;

/**
 * Embedding the admin in the host's own admin panel
 * (ADR 2026-11-05-embeddable-admin), read from the `escalated.ui.embed`
 * bundle configuration. Optional and off by default: while it is off nothing
 * else in the bundle behaves differently.
 *
 * Every key the configuration leaves out falls back to its env var
 * (ESCALATED_EMBED, ESCALATED_EMBED_MODE, ESCALATED_EMBED_PREFIX,
 * ESCALATED_EMBED_FRAME_ANCESTORS), resolved by the container, then to the
 * default. Values are normalised here, so a bad env value cannot open
 * anything up: an unknown mode is `layout`, and only exact http(s) origins
 * survive in the frame-ancestors list.
 */
final class EmbedConfig
{
    public const MODES = ['layout', 'iframe'];

    public const DEFAULT_PREFIX = 'admin/support';

    /**
     * Route default (and so request attribute) the route loader puts on the
     * admin routes it mounts under the host prefix.
     */
    public const REQUEST_ATTRIBUTE = '_escalated_embedded';

    private readonly bool $enabled;
    private readonly string $mode;
    private readonly string $prefix;
    /** @var list<string> */
    private readonly array $guards;
    private readonly ?string $layout;
    private readonly ?string $rootView;
    /** @var list<string> */
    private readonly array $frameAncestors;
    /** @var array<string, mixed> */
    private readonly array $parameters;

    /**
     * @param list<string>|null        $guards         security attributes the user must be granted on the embedded admin
     * @param list<string>|string|null $frameAncestors origins, as a list or a comma-separated string
     */
    public function __construct(
        mixed $enabled = false,
        mixed $mode = 'layout',
        mixed $prefix = self::DEFAULT_PREFIX,
        ?array $guards = null,
        private readonly bool $replaceAdminGate = false,
        ?string $layout = null,
        ?string $rootView = null,
        array|string|null $frameAncestors = [],
        bool $uiEnabled = true,
    ) {
        $this->parameters = ['escalated.ui.embed.enabled' => $enabled, 'escalated.ui.embed.prefix' => $prefix];
        $this->enabled = $uiEnabled && self::toBool($enabled);
        $this->mode = \is_string($mode) && \in_array($mode, self::MODES, true) ? $mode : 'layout';
        $prefix = \is_string($prefix) ? trim(trim($prefix), '/') : '';
        $this->prefix = '' === $prefix ? self::DEFAULT_PREFIX : $prefix;
        $this->guards = array_values(array_filter($guards ?? [], static fn (mixed $g): bool => \is_string($g) && '' !== $g));
        $this->layout = null === $layout || '' === $layout ? null : $layout;
        $this->rootView = null === $rootView || '' === $rootView ? null : $rootView;
        $this->frameAncestors = self::origins($frameAncestors);
    }

    /**
     * The `escalated:` configuration that embeds the admin: the bundle-config
     * form of escalated-laravel's Escalated::embedAdmin(). For a PHP config
     * file, e.g. config/packages/escalated.php:
     *
     *     $container->extension('escalated', EmbedConfig::embedAdmin(
     *         prefix: 'admin/support',
     *         guards: ['ROLE_ADMIN'],
     *         layout: 'Layouts/AdminLayout',
     *     ));
     *
     * @param list<string>|null $guards         security attributes for the embedded admin; null adds none
     * @param list<string>      $frameAncestors iframe mode: origins allowed to frame the admin
     *
     * @return array{ui: array{embed: array<string, mixed>}}
     */
    public static function embedAdmin(
        string $prefix = self::DEFAULT_PREFIX,
        ?array $guards = null,
        ?string $layout = null,
        ?string $rootView = null,
        string $mode = 'layout',
        bool $replaceAdminGate = false,
        array $frameAncestors = [],
    ): array {
        if (!\in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(sprintf("EmbedConfig::embedAdmin() mode must be 'layout' or 'iframe', got '%s'.", $mode));
        }

        return ['ui' => ['embed' => [
            'enabled' => true,
            'mode' => $mode,
            'prefix' => $prefix,
            'guards' => $guards,
            'replace_admin_gate' => $replaceAdminGate,
            'layout' => $layout,
            'root_view' => $rootView,
            'frame_ancestors' => $frameAncestors,
        ]]];
    }

    /**
     * The container parameters the route table depends on, as resolved, for
     * the router cache's freshness check.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** The host prefix the admin is mounted under while embedded, without slashes. */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Security attributes (roles, or anything a voter decides) the user must
     * be granted on every embedded admin route, checked before Escalated's own
     * gates. Empty: none beyond the host's firewall and access_control.
     *
     * @return list<string>
     */
    public function guards(): array
    {
        return $this->guards;
    }

    public function replacesAdminGate(): bool
    {
        return $this->enabled && $this->replaceAdminGate;
    }

    public function layout(): ?string
    {
        return $this->layout;
    }

    public function rootView(): ?string
    {
        return $this->rootView;
    }

    /**
     * iframe mode: the exact `scheme://host[:port]` origins allowed to frame
     * the admin.
     *
     * @return list<string>
     */
    public function frameAncestors(): array
    {
        return $this->frameAncestors;
    }

    public function frameAncestorsDirective(): string
    {
        return trim("frame-ancestors 'self' ".implode(' ', $this->frameAncestors));
    }

    /** Whether the request is served by an embedded admin route. */
    public function isEmbeddedRequest(?Request $request): bool
    {
        return $this->enabled && null !== $request && true === $request->attributes->get(self::REQUEST_ATTRIBUTE);
    }

    /**
     * Whether Escalated's admin gate is replaced by the host's guards for this
     * request: on an embedded admin route with `replace_admin_gate` only.
     */
    public function replacesAdminGateFor(?Request $request): bool
    {
        return $this->replacesAdminGate() && $this->isEmbeddedRequest($request);
    }

    /**
     * The `escalated.embed` shared prop (shared only while embedding is on).
     *
     * @return array{enabled: bool, mode: string, layout: ?string, route: ?string, base_url: string, allowed_origins: list<string>}
     */
    public function shared(?Request $request): array
    {
        $embedded = $this->isEmbeddedRequest($request);
        $route = $embedded ? $request?->attributes->get('_route') : null;

        return [
            'enabled' => $embedded,
            'mode' => $this->mode,
            'layout' => $this->layout,
            'route' => \is_string($route) ? $route : null,
            'base_url' => ($request?->getBaseUrl() ?? '').'/'.$this->prefix,
            'allowed_origins' => 'iframe' === $this->mode ? $this->frameAncestors : [],
        ];
    }

    /**
     * @return list<string>
     */
    private static function origins(array|string|null $configured): array
    {
        if (\is_string($configured)) {
            $configured = explode(',', $configured);
        }

        $origins = [];
        foreach ($configured ?? [] as $entry) {
            $origin = \is_string($entry) ? self::origin(trim($entry)) : null;
            if (null !== $origin && !\in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
        }

        return $origins;
    }

    private static function origin(string $value): ?string
    {
        if ('' === $value || str_contains($value, '*')) {
            return null;
        }

        $parts = parse_url($value);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $path = $parts['path'] ?? '';
        if (('' !== $path && '/' !== $path) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $origin = $scheme.'://'.strtolower($parts['host']);

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }

    private static function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \is_scalar($value) && (filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false);
    }
}
