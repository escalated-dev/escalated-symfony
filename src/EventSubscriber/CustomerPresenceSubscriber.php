<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventSubscriber;

use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\AgentPanel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Customer-portal requests (`escalated.customer.*`) record that the signed-in
 * customer is on the site, throttled (ADR 2026-11-05-agent-panel-data). Staff
 * browsing the portal are not recorded, and presence never fails the request.
 */
class CustomerPresenceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AgentPanel $panel,
        private readonly UserKeyResolver $userKeys,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $route = (string) $event->getRequest()->attributes->get('_route', '');
        if (!str_starts_with($route, 'escalated.customer.')) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof UserInterface) {
            return;
        }
        $this->panel->quietly(function () use ($user): void {
            $this->panel->seeUser($this->userKeys->resolve($user), $user::class);
        });
    }
}
