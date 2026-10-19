<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventSubscriber;

use Escalated\Symfony\Widget\WidgetOrigins;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS for the widget API ({route_prefix}/widget/api/..., chat included).
 *
 * Only origins on the allow list ({@see WidgetOrigins}) get CORS headers. For
 * such a request:
 *
 *  - a preflight is answered with 204 before routing, so OPTIONS never reaches
 *    the router's 405;
 *  - every answer, including 4xx and exception responses, carries
 *    Access-Control-Allow-Origin with that origin, exposes Retry-After and
 *    varies on Origin. Credentials are never allowed;
 *  - the request's cookies are dropped before the session and firewall read
 *    them, so a cross-site embed always runs as a guest and never as whoever
 *    is signed in to Escalated in the same browser. Guest tokens still work.
 *
 * Preflights from any other origin get 204 without a grant. Other requests
 * pass through untouched, without CORS headers.
 */
final class WidgetCorsSubscriber implements EventSubscriberInterface
{
    public const ALLOW_METHODS = 'GET, POST, OPTIONS';

    public const ALLOW_HEADERS = 'Accept, Authorization, Content-Type, X-Requested-With, X-Guest-Token';

    public const MAX_AGE = 600;

    private const SEEN = '_escalated_widget_cors';

    public function __construct(
        private readonly WidgetOrigins $origins,
        private readonly string $routePrefix = '/support',
        private readonly bool $uiEnabled = true,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before the session (128), the router (32) and the firewall (8).
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->inScope($request)) {
            return;
        }

        $request->attributes->set(self::SEEN, true);
        $origin = WidgetOrigins::crossOrigin($request);
        $allowed = null !== $origin && $this->origins->allows($origin);

        if ('OPTIONS' === $request->getRealMethod() && $request->headers->has('Access-Control-Request-Method')) {
            $response = new Response('', Response::HTTP_NO_CONTENT, ['Vary' => 'Origin']);
            if ($allowed) {
                $response->headers->set('Access-Control-Allow-Methods', self::ALLOW_METHODS);
                $response->headers->set('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
                $response->headers->set('Access-Control-Max-Age', (string) self::MAX_AGE);
                self::grant($response, $origin);
            }
            $event->setResponse($response);

            return;
        }

        if (!$allowed) {
            return;
        }

        $request->attributes->set(WidgetOrigins::ATTRIBUTE, $origin);
        $request->cookies->replace([]);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()) {
            return;
        }

        $origin = $request->attributes->get(WidgetOrigins::ATTRIBUTE);
        if (!\is_string($origin)) {
            // An answer produced before onKernelRequest ran (an earlier
            // listener threw) still gets the grant an allowed origin is due.
            if ($request->attributes->has(self::SEEN) || !$this->inScope($request)) {
                return;
            }
            $origin = WidgetOrigins::crossOrigin($request);
            if (null === $origin || !$this->origins->allows($origin)) {
                return;
            }
        }

        self::grant($event->getResponse(), $origin);
    }

    private function inScope(Request $request): bool
    {
        if (!$this->uiEnabled) {
            return false;
        }

        $base = rtrim($this->routePrefix, '/').'/widget/api';
        $path = $request->getPathInfo();

        // The script sends its own `Access-Control-Allow-Origin: *`.
        return ($path === $base || str_starts_with($path, $base.'/')) && $path !== $base.'/embed.js';
    }

    private static function grant(Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Expose-Headers', 'Retry-After');
        $response->headers->remove('Access-Control-Allow-Credentials');

        $vary = $response->getVary();
        if (!\in_array('Origin', $vary, true)) {
            $response->setVary('Origin', false);
        }
    }
}
