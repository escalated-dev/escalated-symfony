<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Plugin\PluginRegistry;
use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\UserPermissions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Shares common data with the Inertia frontend on every Escalated controller request.
 *
 * If Inertia is not installed, this subscriber is a no-op.
 */
class InertiaDataSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly AuthorizationCheckerInterface $authChecker,
        private readonly EntityManagerInterface $em,
        private readonly string $routePrefix,
        private readonly bool $newslettersEnabled = false,
        private readonly ?PluginRegistry $plugins = null,
        private readonly AccessFeatures $accessFeatures = new AccessFeatures(),
        private readonly ?UserPermissions $userPermissions = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onController',
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route', '');

        // Only inject data for Escalated routes
        if (!str_starts_with((string) $route, 'escalated.')) {
            return;
        }

        $user = $this->security->getUser();

        $isAgent = $this->authChecker->isGranted('ESCALATED_AGENT');
        $isAdmin = $this->authChecker->isGranted('ESCALATED_ADMIN');

        $data = [
            'prefix' => $this->routePrefix,
            'is_agent' => $isAgent,
            'is_admin' => $isAdmin,
            'permissions' => $this->sharedPermissions($user, $isAgent || $isAdmin),
            'features' => [
                'newsletters' => $this->newslettersEnabled,
                ...$this->accessFeatures->shared(),
            ],
        ];

        if ($user instanceof UserInterface) {
            try {
                $profile = $this->em->getRepository(AgentProfile::class)
                    ->findOneBy(['userId' => $user->getUserIdentifier()]);
                $data['agent_type'] = $profile?->getAgentType() ?? 'full';
            } catch (\Throwable) {
                // Agent profiles table may not exist yet
            }
        }

        // Active plugins' menu items, page components and dashboard widgets,
        // as usePluginExtensions() reads them; {} when none is active.
        $data['plugins'] = new \stdClass();
        try {
            $extensions = $this->plugins?->uiExtensions() ?? [];
            if ([] !== $extensions) {
                $data['plugins'] = $extensions;
            }
        } catch (\Throwable) {
            // The plugin tables may not exist yet.
        }

        // Store shared data in the request attributes for Inertia renderers to pick up
        $request->attributes->set('_escalated_shared', $data);
    }

    /**
     * The permission slugs the shared frontend checks (ADR
     * 2026-10-29-configurable-access-features): the user's slugs while roles &
     * permissions is on; while it is off the gates decide everything, so `*`
     * for staff and nothing for anyone else.
     *
     * @return list<string>
     */
    private function sharedPermissions(?UserInterface $user, bool $isStaff): array
    {
        if (!$user instanceof UserInterface) {
            return [];
        }

        if (!$this->accessFeatures->rolesPermissions()) {
            return $isStaff ? ['*'] : [];
        }

        try {
            return $this->userPermissions?->of($user) ?? [];
        } catch (\Throwable) {
            // The roles tables may not exist yet.
            return [];
        }
    }
}
