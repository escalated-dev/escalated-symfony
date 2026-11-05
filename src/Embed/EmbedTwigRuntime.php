<?php

declare(strict_types=1);

namespace Escalated\Symfony\Embed;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * The runtime behind {@see EmbedTwigExtension}.
 */
final class EmbedTwigRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly EmbedConfig $embed,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminNavigation(): array
    {
        return $this->navigation->adminNavigation();
    }

    /**
     * @return array<string, mixed>
     */
    public function embed(): array
    {
        return $this->embed->shared($this->requests->getCurrentRequest());
    }
}
