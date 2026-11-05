<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures\Embed;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A host's security headers, as a headers bundle or the host's own listener
 * sends them: `X-Frame-Options: DENY` and a CSP on every response. Also
 * reports whether {@see HostAdminVoter} ran on the request.
 */
final class HostHeadersSubscriber implements EventSubscriberInterface
{
    public const CSP = "default-src 'self'";

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Content-Security-Policy', self::CSP);

        $mark = $event->getRequest()->attributes->get(HostAdminVoter::MARK);
        if (\is_string($mark)) {
            $headers->set('X-Host-Guard', $mark);
        }
    }
}
