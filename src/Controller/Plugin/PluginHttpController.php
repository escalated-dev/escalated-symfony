<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Plugin;

use Escalated\Symfony\Plugin\Bridge\BridgeException;
use Escalated\Symfony\Plugin\PluginHttp;
use Escalated\Symfony\Plugin\PluginRegistry;
use Escalated\Symfony\Plugin\RegisteredPlugin;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Plugin endpoints and webhooks (the plugin contract's "Endpoints and
 * webhooks"):
 *
 *  - `{METHOD} {prefix}/api/plugins/{slug}{path}`: behind the admin guard and
 *    the endpoint's capability. A state-changing call must come from a page
 *    script (`X-Requested-With` or `X-Inertia`), which a cross-site form
 *    cannot send with an admin's session.
 *  - `{METHOD} {prefix}/webhooks/plugins/{slug}{path}`: no guard; the plugin
 *    authenticates the sender.
 *
 * Only active plugins answer. The request is matched against the manifest's
 * paths (`:param` segments allowed) and dispatched on the manifest path.
 */
class PluginHttpController extends AbstractController
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/plugins/{slug}/{path}', name: 'escalated.plugins.endpoint', requirements: ['path' => '.*'], defaults: ['path' => ''])]
    public function endpoint(string $slug, string $path, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $plugin = $this->plugins->active($slug);
        $match = null === $plugin ? null : self::match($plugin->endpoints(), $request->getMethod(), $path);
        if (null === $plugin || null === $match) {
            return new JsonResponse(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }
        [$declared, $params] = $match;
        if (!$this->plugins->allows($declared['capability'])) {
            return new JsonResponse(['message' => 'This endpoint needs a capability you do not have.'], Response::HTTP_FORBIDDEN);
        }
        if (!\in_array($request->getMethod(), self::SAFE_METHODS, true)
            && !$request->headers->has('X-Requested-With') && !$request->headers->has('X-Inertia')) {
            return new JsonResponse(['message' => 'Send this request from a page script (X-Requested-With).'], Response::HTTP_FORBIDDEN);
        }

        return $this->call($plugin, 'endpoint', $declared['method'], $declared['path'], $request, $params);
    }

    #[Route('/webhooks/plugins/{slug}/{path}', name: 'escalated.plugins.webhook', requirements: ['path' => '.*'], defaults: ['path' => ''])]
    public function webhook(string $slug, string $path, Request $request): Response
    {
        $plugin = $this->plugins->active($slug);
        $match = null === $plugin ? null : self::match($plugin->webhooks(), $request->getMethod(), $path);
        if (null === $plugin || null === $match) {
            return new JsonResponse(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }
        [$declared, $params] = $match;

        return $this->call($plugin, 'webhook', $declared['method'], $declared['path'], $request, $params);
    }

    /**
     * @param array<string, string> $params
     */
    private function call(RegisteredPlugin $plugin, string $kind, string $method, string $path, Request $request, array $params): Response
    {
        try {
            $result = $this->plugins->callHttp($plugin, $kind, $method, $path, PluginHttp::request($request, $params));
        } catch (BridgeException $e) {
            if ($e->pluginError) {
                return $this->failed($plugin, $kind, $method, $path, $e);
            }
            $this->logger->warning(sprintf('[Escalated plugins] %s %s %s %s: %s', $plugin->name, $kind, $method, $path, $e->getMessage()));

            return new JsonResponse(['message' => 'The plugin runtime is not available.'], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            return $this->failed($plugin, $kind, $method, $path, $e);
        }

        return PluginHttp::response($result);
    }

    private function failed(RegisteredPlugin $plugin, string $kind, string $method, string $path, \Throwable $e): Response
    {
        $this->logger->error(sprintf('[Escalated plugins] %s %s %s %s threw: %s', $plugin->name, $kind, $method, $path, $e->getMessage()), ['exception' => $e]);

        return new JsonResponse(['message' => 'The plugin could not handle this request.'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * The declared route matching the request, and the values of its
     * `:param` segments. HEAD is answered by a GET route.
     *
     * @param list<array{method: string, path: string, capability: ?string}> $routes
     *
     * @return array{0: array{method: string, path: string, capability: ?string}, 1: array<string, string>}|null
     */
    public static function match(array $routes, string $method, string $path): ?array
    {
        $actual = self::segments($path);
        foreach ([$method, 'HEAD' === $method ? 'GET' : null] as $candidateMethod) {
            if (null === $candidateMethod) {
                continue;
            }
            foreach ($routes as $route) {
                if ($route['method'] !== $candidateMethod) {
                    continue;
                }
                $pattern = self::segments($route['path']);
                if (\count($pattern) !== \count($actual)) {
                    continue;
                }
                $params = [];
                foreach ($pattern as $i => $segment) {
                    if (str_starts_with($segment, ':') && \strlen($segment) > 1) {
                        $params[substr($segment, 1)] = $actual[$i];
                    } elseif ($segment !== $actual[$i]) {
                        continue 2;
                    }
                }

                return [$route, $params];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        $path = trim($path, '/');

        return '' === $path ? [] : explode('/', $path);
    }
}
