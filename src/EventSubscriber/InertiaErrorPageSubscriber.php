<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventSubscriber;

use Escalated\Symfony\Rendering\UiRendererInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers an Inertia visit to one of Escalated's routes that ends in 403, 404,
 * 419, 429, 500 or 503 with the shared frontend's Escalated/Error page.
 *
 * Without it the Inertia client gets Symfony's HTML error page (or a
 * controller's JSON error) back and can only show it in a modal over the
 * screen the user was on. The props are the ones Rails and Laravel send:
 * `status` and a generic message. An exception's or a controller's own text
 * never reaches the page.
 *
 * Thrown errors are answered on kernel.exception, after the security
 * listener has turned a refusal into a 403 and after any host listener with
 * a higher priority, so a host that answers an exception itself still does.
 * Error responses a controller returns are answered on kernel.response.
 *
 * Left alone: requests without `X-Inertia` (full page loads, JSON clients),
 * routes that are not Escalated's (`escalated.*`) or are its API, widget or
 * inbound email routes, responses that are already Inertia pages, and a 500
 * while kernel.debug is on, so Symfony's exception page stays where a
 * developer wants it. Does nothing while the UI is disabled.
 */
final class InertiaErrorPageSubscriber implements EventSubscriberInterface
{
    public const PAGE = 'Escalated/Error';

    public const MESSAGES = [
        403 => 'You are not authorized to perform this action.',
        404 => 'The requested resource was not found.',
        419 => 'Your session has expired. Refresh the page and try again.',
        429 => 'Too many requests. Wait a moment and try again.',
        500 => 'Something went wrong on our end.',
        503 => 'The service is temporarily unavailable. Try again shortly.',
    ];

    /** Escalated routes whose clients are not Inertia pages. */
    private const NOT_PANEL = ['escalated.api.', 'escalated.widget.', 'escalated.inbound_email.'];

    /** Set on a request whose error was thrown, so kernel.response leaves its answer alone. */
    private const THREW = '_escalated_threw';

    public function __construct(
        private readonly UiRendererInterface $renderer,
        private readonly bool $uiEnabled,
        private readonly bool $debug,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => [
                ['markThrown', 2048],
                // After the security listener (1), before Symfony's ErrorListener (-128).
                ['onException', -64],
            ],
            KernelEvents::RESPONSE => ['onResponse', 0],
        ];
    }

    public function markThrown(ExceptionEvent $event): void
    {
        $event->getRequest()->attributes->set(self::THREW, true);
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $throwable = $event->getThrowable();
        $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;

        $page = $this->pageFor($event->getRequest(), $status);
        if (null !== $page) {
            $event->setResponse($page);
            // An HttpException's status would otherwise be applied on top.
            $event->allowCustomResponseCode();
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->get(self::THREW)) {
            return;
        }

        $response = $event->getResponse();
        if (self::isInertiaPage($response)) {
            return;
        }

        $page = $this->pageFor($request, $response->getStatusCode());
        if (null !== $page) {
            $event->setResponse($page);
        }
    }

    private function pageFor(Request $request, int $status): ?Response
    {
        if (!$this->uiEnabled || !isset(self::MESSAGES[$status]) || !$request->headers->has('X-Inertia')) {
            return null;
        }
        if (!self::isPanelRoute($request) || (500 === $status && $this->debug)) {
            return null;
        }

        $page = $this->renderer->render(self::PAGE, ['status' => $status, 'message' => self::MESSAGES[$status]]);
        $page->setStatusCode($status);

        return $page;
    }

    private static function isPanelRoute(Request $request): bool
    {
        $route = (string) $request->attributes->get('_route', '');
        if (!str_starts_with($route, 'escalated.')) {
            return false;
        }

        foreach (self::NOT_PANEL as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private static function isInertiaPage(Response $response): bool
    {
        if ($response->headers->has('X-Inertia')) {
            return true;
        }

        // The renderer's JSON fallback, used when no Inertia bundle is installed.
        if (!str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return false;
        }
        $body = json_decode((string) $response->getContent(), true);

        return \is_array($body) && isset($body['component'], $body['props']);
    }
}
