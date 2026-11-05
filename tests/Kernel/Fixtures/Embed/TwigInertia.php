<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures\Embed;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * The server side of an Inertia bundle, as far as Escalated uses one: the
 * service API of rompetomp/inertia-bundle and skipthedragon/inertia-bundle
 * (share, setRootView / getRootView, render). An Inertia visit gets the page
 * as JSON; a first visit gets the root view rendered by Twig with `page`,
 * which the template mounts with `{{ inertia(page) }}`
 * ({@see InertiaTwigExtension}).
 */
final class TwigInertia
{
    private string $rootView = 'app.html.twig';

    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(
        private readonly Environment $twig,
        private readonly RequestStack $requests,
    ) {
    }

    public function share(string $key, mixed $value = null): void
    {
        $this->shared[$key] = $value;
    }

    public function getShared(?string $key = null): mixed
    {
        return null === $key ? $this->shared : ($this->shared[$key] ?? null);
    }

    public function setRootView(string $rootView): void
    {
        $this->rootView = $rootView;
    }

    public function getRootView(): string
    {
        return $this->rootView;
    }

    /**
     * @param array<string, mixed> $props
     * @param array<string, mixed> $viewData
     * @param array<string, mixed> $context
     */
    public function render(string $component, array $props = [], array $viewData = [], array $context = [], ?string $url = null): Response
    {
        $request = $this->requests->getCurrentRequest();
        $page = [
            'component' => $component,
            'props' => [...$this->shared, ...$props],
            'url' => $url ?? $request?->getRequestUri() ?? '/',
            'version' => '',
        ];

        if (null !== $request && $request->headers->has('X-Inertia')) {
            return new JsonResponse($page, Response::HTTP_OK, ['Vary' => 'Accept', 'X-Inertia' => 'true']);
        }

        return new Response($this->twig->render($this->rootView, ['page' => $page, ...$viewData]));
    }
}
