<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventSubscriber;

use Escalated\Symfony\Security\ApiTokenAuthenticator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every answer of the mobile guest API, success or error, carries a grant or
 * a guest's correspondence: never cached, and no referrer hands the grant in
 * the path on to another site. Escalated-laravel's GuestPrivacyHeaders.
 */
final class GuestPrivacyHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Late, so nothing after it rewrites Cache-Control.
        return [KernelEvents::RESPONSE => ['onKernelResponse', -1024]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $route = (string) $event->getRequest()->attributes->get('_route', '');
        if (!str_starts_with($route, ApiTokenAuthenticator::GUEST_ROUTE_PREFIX)) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'private, no-store');
        $headers->set('Referrer-Policy', 'no-referrer');
    }
}
