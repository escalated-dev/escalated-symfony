<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Plugin\PluginHttp;
use Escalated\Symfony\Plugin\PluginRegistry;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin > Plugins (Escalated/Admin/Plugins/Index) and the pages plugins
 * declare (Escalated/Plugin/Page), per the plugin contract.
 *
 * Plugins are installed by whoever deploys the host (container services and
 * npm packages), so there is no upload: `can_upload` is false and the upload
 * route does not exist.
 */
#[Route('/admin/plugins')]
class PluginController extends UiController
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly UiRendererInterface $renderer,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'escalated.admin.plugins.index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Plugins/Index', [
            'plugins' => $this->plugins->listing(),
            'can_upload' => false,
        ]);
    }

    #[Route('/{slug}/activate', name: 'escalated.admin.plugins.activate', methods: ['POST'])]
    public function activate(string $slug, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        if (!$this->plugins->activate($slug)) {
            return $this->unknown($request);
        }

        return $this->done($request, 'success', 'Plugin activated.');
    }

    #[Route('/{slug}/deactivate', name: 'escalated.admin.plugins.deactivate', methods: ['POST'])]
    public function deactivate(string $slug, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        if (!$this->plugins->deactivate($slug)) {
            return $this->unknown($request);
        }

        return $this->done($request, 'success', 'Plugin deactivated.');
    }

    // `upload` is not a slug, so POST /admin/plugins/upload is a 404 rather
    // than a 405 from this route.
    #[Route('/{slug}', name: 'escalated.admin.plugins.destroy', requirements: ['slug' => '(?!upload$)[^/]+'], methods: ['DELETE'])]
    public function destroy(string $slug, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return match ($this->plugins->uninstall($slug)) {
            'unknown' => $this->unknown($request),
            'refused' => $this->done($request, 'error', 'This plugin is installed by a package manager; remove it there.', Response::HTTP_CONFLICT),
            default => $this->done($request, 'success', 'Plugin uninstalled.'),
        };
    }

    /**
     * A page an active plugin declares, rendered as Escalated/Plugin/Page
     * with the props its own `GET /{component}` endpoint returns.
     */
    #[Route('/{slug}/{route}', name: 'escalated.plugin.page', requirements: ['route' => '.+'], methods: ['GET'])]
    public function page(string $slug, string $route, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $plugin = $this->plugins->active($slug);
        $page = $plugin?->page($route);
        if (null === $plugin || null === $page) {
            throw $this->createNotFoundException('Plugin page not found.');
        }
        if (!$this->plugins->allows($page['capability'])) {
            throw $this->createAccessDeniedException('This plugin page needs a capability you do not have.');
        }

        return $this->renderer->render('Escalated/Plugin/Page', [
            'plugin' => $plugin->name,
            'component' => $page['component'],
            'layout' => $page['layout'],
            'props' => $this->pageProps($plugin, $page['component'], $request),
        ]);
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    private function pageProps(\Escalated\Symfony\Plugin\RegisteredPlugin $plugin, string $component, Request $request): array|\stdClass
    {
        $path = '/'.$component;
        $declared = array_filter($plugin->endpoints(), static fn (array $e): bool => 'GET' === $e['method'] && $e['path'] === $path);
        if ([] === $declared) {
            return new \stdClass();
        }

        try {
            $result = $this->plugins->callHttp($plugin, 'endpoint', 'GET', $path, PluginHttp::request($request, []));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Escalated plugins] %s failed to give props for %s: %s', $plugin->name, $component, $e->getMessage()));

            return new \stdClass();
        }

        if (\is_array($result) && \array_key_exists('__escalated_http', $result)) {
            $ok = \is_int($result['status'] ?? null) && $result['status'] < 300 && 'json' === ($result['format'] ?? null);
            $result = $ok ? ($result['body'] ?? null) : null;
        }

        return \is_array($result) && [] !== $result ? $result : new \stdClass();
    }

    private function unknown(Request $request): Response
    {
        if ($this->wantsJson($request)) {
            return new JsonResponse(['message' => 'Plugin not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->done($request, 'error', 'Plugin not found.');
    }

    private function done(Request $request, string $type, string $message, int $jsonStatus = Response::HTTP_OK): Response
    {
        if ($this->wantsJson($request)) {
            return new JsonResponse(['message' => $message], 'success' === $type ? Response::HTTP_OK : $jsonStatus);
        }

        $this->addFlash($type, $message);

        return $this->redirectToRoute('escalated.admin.plugins.index', [], Response::HTTP_SEE_OTHER);
    }

    private function wantsJson(Request $request): bool
    {
        return !$request->headers->has('X-Inertia') && 'json' === $request->getPreferredFormat();
    }
}
