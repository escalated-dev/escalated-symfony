<?php

declare(strict_types=1);

namespace Escalated\Symfony\Rendering;

use Escalated\Symfony\Embed\EmbedConfig;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inertia.js-based UI renderer.
 *
 * Requires either rompetomp/inertia-bundle or skipthedragon/inertia-bundle.
 * Falls back to a JSON response if no Inertia service is available.
 *
 * While the admin is embedded in the host's admin panel (`ui.embed`, ADR
 * 2026-11-05-embeddable-admin), the Escalated data the page needs
 * (`escalated`, with `embed` and `navigation`) is shared with the Inertia
 * service, and embedded admin pages are rendered in `ui.embed.root_view`
 * when it is set. With embedding off, rendering is unchanged.
 */
class InertiaUiRenderer implements UiRendererInterface
{
    /**
     * The Inertia service instance (injected from the Inertia bundle).
     * Typed as `object` because the exact class depends on which Inertia bundle is installed.
     */
    private ?object $inertia;

    public function __construct(
        ?object $inertia = null,
        private readonly ?RequestStack $requests = null,
        private readonly ?EmbedConfig $embed = null,
    ) {
        $this->inertia = $inertia;
    }

    public function render(string $page, array $props = []): Response
    {
        // rompetomp/inertia-bundle provides Rompetomp\InertiaBundle\Service\InertiaInterface
        // skipthedragon/inertia-bundle provides a similar service
        if (null !== $this->inertia && method_exists($this->inertia, 'render')) {
            $restoreRootView = $this->applyEmbed();

            try {
                $response = $this->inertia->render($page, $props);
            } finally {
                if (null !== $restoreRootView) {
                    $this->inertia->setRootView($restoreRootView);
                }
            }

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

    /**
     * Embedding on: share the `escalated` data, and switch to the embed root
     * view for an embedded admin page. Returns the root view to restore
     * after rendering, or null.
     */
    private function applyEmbed(): ?string
    {
        if (null === $this->embed || !$this->embed->enabled() || null === $this->inertia) {
            return null;
        }

        $request = $this->requests?->getCurrentRequest();
        $shared = $request?->attributes->get('_escalated_shared');
        if (\is_array($shared) && method_exists($this->inertia, 'share')) {
            $this->inertia->share('escalated', $shared);
        }

        $rootView = $this->embed->rootView();
        if (null === $rootView || !$this->embed->isEmbeddedRequest($request)
            || !method_exists($this->inertia, 'setRootView') || !method_exists($this->inertia, 'getRootView')) {
            return null;
        }

        $previous = $this->inertia->getRootView();
        $this->inertia->setRootView($rootView);

        return \is_string($previous) ? $previous : null;
    }
}
