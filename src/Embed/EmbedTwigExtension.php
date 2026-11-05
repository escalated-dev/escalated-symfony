<?php

declare(strict_types=1);

namespace Escalated\Symfony\Embed;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig functions for a host that renders its admin layout with Twig
 * (ADR 2026-11-05-embeddable-admin, docs/embedding-admin.md):
 *
 * - `escalated_admin_navigation()`: Escalated's admin menu for the signed-in
 *   user ({@see AdminNavigation}), with the current page `active`;
 * - `escalated_embed()`: the `escalated.embed` data for the current request
 *   (`enabled`, `mode`, `layout`, `route`, `base_url`, `allowed_origins`).
 *
 * Both are served lazily by {@see EmbedTwigRuntime}: nothing is built until a
 * template calls one.
 */
final class EmbedTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('escalated_admin_navigation', [EmbedTwigRuntime::class, 'adminNavigation']),
            new TwigFunction('escalated_embed', [EmbedTwigRuntime::class, 'embed']),
        ];
    }
}
