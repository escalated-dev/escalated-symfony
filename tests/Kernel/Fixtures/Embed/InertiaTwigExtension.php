<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures\Embed;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The `inertia(page)` and `inertiaHead(page)` Twig functions the Inertia
 * bundles provide: the mount point, with the page object in `data-page`.
 */
final class InertiaTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('inertia', static function (array $page, string $id = 'app'): string {
                return sprintf(
                    '<div id="%s" data-page="%s"></div>',
                    htmlspecialchars($id, \ENT_QUOTES),
                    htmlspecialchars((string) json_encode($page, \JSON_UNESCAPED_SLASHES), \ENT_QUOTES),
                );
            }, ['is_safe' => ['html']]),
            // Server-side rendering's head tags; none without SSR.
            new TwigFunction('inertiaHead', static fn (array $page): string => '', ['is_safe' => ['html']]),
        ];
    }
}
