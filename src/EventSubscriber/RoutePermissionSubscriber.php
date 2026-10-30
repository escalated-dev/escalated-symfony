<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventSubscriber;

use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\EnsureAdminVoter;
use Escalated\Symfony\Security\EnsureAgentVoter;
use Escalated\Symfony\Security\RoutePermissions;
use Escalated\Symfony\Security\UserPermissions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Requires the route's permission ({@see RoutePermissions}) from the user's
 * roles while roles & permissions is switched on; does nothing while it is
 * off (ADR 2026-10-29-configurable-access-features). The port of
 * escalated-laravel's EnforceRoutePermissions middleware.
 *
 * The ESCALATED_ADMIN / ESCALATED_AGENT gates still decide who reaches an
 * area at all: a visitor who is not signed in, or not staff, is left to the
 * controller's gate, which refuses them as before. For staff this adds the
 * permission on top, and failing it answers 403. A staff route missing from
 * the map is refused rather than let through. API tokens act as their owner,
 * so the staff API checks the owner's permissions.
 */
final class RoutePermissionSubscriber implements EventSubscriberInterface
{
    /** After the feature gate (a hidden screen is a 404 first). */
    public const PRIORITY = 32;

    public function __construct(
        private readonly AccessFeatures $features,
        private readonly UserPermissions $permissions,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', self::PRIORITY]];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->features->rolesPermissions()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if (!RoutePermissions::governs($route)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof UserInterface) {
            return;
        }
        if (!$this->security->isGranted(EnsureAgentVoter::ATTRIBUTE) && !$this->security->isGranted(EnsureAdminVoter::ATTRIBUTE)) {
            return;
        }

        $required = RoutePermissions::requiredFor($route, self::payload($request));
        if (null === $required) {
            throw new AccessDeniedHttpException('This route has no permission mapping.');
        }

        $granted = $this->permissions->of($user);
        foreach ($required as $slug) {
            if (!\in_array($slug, $granted, true)) {
                throw new AccessDeniedHttpException('You do not have the required permission: '.$slug);
            }
        }
    }

    /**
     * The request body, whether it came as JSON (the shared pages and the
     * API) or as a form.
     *
     * @return array<string, mixed>
     */
    private static function payload(Request $request): array
    {
        $content = (string) $request->getContent();
        if ('' !== $content && str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $decoded = json_decode($content, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }
}
