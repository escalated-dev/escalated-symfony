<?php

declare(strict_types=1);

namespace Escalated\Symfony\Rendering;

use Symfony\Component\HttpFoundation\Response;

/**
 * Abstraction for rendering UI pages.
 *
 * The default implementation delegates to Inertia. Teams that want
 * Twig, Turbo, or another UI can provide their own implementation.
 */
interface UiRendererInterface
{
    /**
     * Render a named page with the given props.
     *
     * @param string $page  Page/component identifier (e.g. 'Escalated/Agent/Dashboard')
     * @param array  $props Data to pass to the page
     */
    public function render(string $page, array $props = []): Response;
}
