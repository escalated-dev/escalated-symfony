<?php

declare(strict_types=1);

namespace Escalated\Symfony\Rendering;

use Symfony\Component\HttpFoundation\Response;

/**
 * Inertia.js-based UI renderer.
 *
 * Requires either rompetomp/inertia-bundle or skipthedragon/inertia-bundle.
 * Falls back to a JSON response if no Inertia service is available.
 */
class InertiaUiRenderer implements UiRendererInterface
{
    /**
     * The Inertia service instance (injected from the Inertia bundle).
     * Typed as `object` because the exact class depends on which Inertia bundle is installed.
     */
    private ?object $inertia;

    public function __construct(?object $inertia = null)
    {
        $this->inertia = $inertia;
    }

    public function render(string $page, array $props = []): Response
    {
        // rompetomp/inertia-bundle provides Rompetomp\InertiaBundle\Service\InertiaInterface
        // skipthedragon/inertia-bundle provides a similar service
        if ($this->inertia !== null && method_exists($this->inertia, 'render')) {
            $response = $this->inertia->render($page, $props);

            // The Inertia render may return a Response directly or an Inertia response object
            if ($response instanceof Response) {
                return $response;
            }

            // Some Inertia bundles return a response-like object; convert to Symfony Response
            if (method_exists($response, 'toResponse')) {
                return $response->toResponse();
            }
        }

        // Fallback: return props as JSON (useful for headless/API-only mode)
        return new Response(
            json_encode(['component' => $page, 'props' => $props], JSON_THROW_ON_ERROR),
            Response::HTTP_OK,
            ['Content-Type' => 'application/json']
        );
    }
}
