<?php

declare(strict_types=1);

namespace Escalated\Symfony\Embed;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The embedded admin routes (ADR 2026-11-05-embeddable-admin), and no other
 * route. Does nothing while embedding is off.
 *
 * - Request: the host's guards (`ui.embed.guards`), every one of which the
 *   user must be granted, before any of Escalated's own checks (the feature
 *   switches, two-factor, route permissions and the admin gate, which all run
 *   later). Refused like a controller's denyAccessUnlessGranted(): the
 *   firewall sends a visitor who is not signed in to its entry point, and
 *   anyone else gets 403.
 * - Response, iframe mode: `Content-Security-Policy: frame-ancestors 'self'
 *   <origins>` added (as a second policy when the host already sends one) and
 *   `X-Frame-Options` removed. This runs after every other response listener,
 *   so host security headers cannot undo it.
 */
final class EmbedSubscriber implements EventSubscriberInterface
{
    /** After the firewall (8) and the bundle's API and KB listeners (7, 6). */
    public const GUARD_PRIORITY = 5;

    /** After every other response listener. */
    public const HEADERS_PRIORITY = -2048;

    public function __construct(
        private readonly EmbedConfig $embed,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', self::GUARD_PRIORITY],
            KernelEvents::RESPONSE => ['onResponse', self::HEADERS_PRIORITY],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->embed->isEmbeddedRequest($event->getRequest())) {
            return;
        }

        foreach ($this->embed->guards() as $attribute) {
            if (!$this->auth->isGranted($attribute)) {
                $exception = new AccessDeniedException('Access Denied.');
                $exception->setAttributes([$attribute]);

                throw $exception;
            }
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || 'iframe' !== $this->embed->mode() || !$this->embed->isEmbeddedRequest($event->getRequest())) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->remove('X-Frame-Options');
        $headers->set('Content-Security-Policy', $this->embed->frameAncestorsDirective(), false);
    }
}
