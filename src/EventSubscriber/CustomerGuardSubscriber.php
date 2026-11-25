<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * `routes.customer_guards`: the host's extra security attributes on every
 * customer portal and customer knowledge base route (`escalated.customer.*`),
 * escalated-laravel's `escalated.routes.customer_middleware`. Empty by
 * default, and then this does nothing.
 *
 * A host whose only Escalated users are staff sets, say, `[ESCALATED_AGENT]`
 * so a signed-in user who was never given Escalated access cannot list or
 * raise tickets. Every attribute must be granted. Refused like a controller's
 * denyAccessUnlessGranted(): the firewall sends a visitor who is not signed in
 * to its entry point, and anyone else gets 403.
 */
final class CustomerGuardSubscriber implements EventSubscriberInterface
{
    /** After the firewall (8). */
    public const PRIORITY = 5;

    public const ROUTE_PREFIX = 'escalated.customer.';

    /**
     * @param list<string> $guards
     */
    public function __construct(
        private readonly AuthorizationCheckerInterface $auth,
        private readonly array $guards = [],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', self::PRIORITY]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ([] === $this->guards || !$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }

        foreach ($this->guards as $attribute) {
            if (!$this->auth->isGranted($attribute)) {
                $exception = new AccessDeniedException('Access Denied.');
                $exception->setAttributes([$attribute]);

                throw $exception;
            }
        }
    }
}
