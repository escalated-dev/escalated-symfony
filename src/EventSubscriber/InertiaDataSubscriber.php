<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Security\NewsletterPermissions;
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

        $data = [
            'prefix' => $this->routePrefix,
            'is_agent' => $this->authChecker->isGranted('ESCALATED_AGENT'),
            'is_admin' => $this->authChecker->isGranted('ESCALATED_ADMIN'),
            'permissions' => $this->grantedPermissions(),
            'features' => [
                'newsletters' => $this->newslettersEnabled,
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

        // Store shared data in the request attributes for Inertia renderers to pick up
        $request->attributes->set('_escalated_shared', $data);
    }

    /**
     * Permission slugs the current user is granted. Symfony has no slug-based
     * permission table — these are resolved through NewsletterPermissionVoter —
     * so we expose the newsletter slugs the frontend gates on.
     *
     * @return list<string>
     */
    private function grantedPermissions(): array
    {
        $granted = [];
        foreach ([NewsletterPermissions::MANAGE, NewsletterPermissions::SEND] as $slug) {
            if ($this->authChecker->isGranted($slug)) {
                $granted[] = $slug;
            }
        }

        return $granted;
    }
}
