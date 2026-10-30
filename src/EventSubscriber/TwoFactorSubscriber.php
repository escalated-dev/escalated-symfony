<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventSubscriber;

use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\ApiTokenUser;
use Escalated\Symfony\Security\EnsureAdminVoter;
use Escalated\Symfony\Security\EnsureAgentVoter;
use Escalated\Symfony\Security\TwoFactorSession;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\TwoFactorService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Two-factor enforcement while the switch (escalated.access.two_factor) is on,
 * the port of escalated-laravel's RequireTwoFactor middleware
 * (ADR 2026-10-29-configurable-access-features):
 *
 * - on an admin or agent route (and the admin plugin endpoints), a user with
 *   a confirmed enrolment who has not passed the challenge in this session is
 *   sent to `escalated.two-factor.challenge` (JSON: 403 with
 *   `two_factor_required: true`);
 * - while two-factor is required, a staff user without an enrolment is sent
 *   to enrol and can reach nothing but the enrolment screen;
 * - every login and logout (the host firewall's LoginSuccessEvent and
 *   LogoutEvent, which SSO sign-in dispatches too) clears the mark, so each
 *   new sign-in is challenged.
 *
 * Token API requests carry no session and are never challenged. Does nothing
 * while the switch is off.
 */
final class TwoFactorSubscriber implements EventSubscriberInterface
{
    /** After the feature gate (64), before the route permissions (32). */
    public const PRIORITY = 48;

    public function __construct(
        private readonly AccessFeatures $features,
        private readonly TwoFactorService $twoFactor,
        private readonly Security $security,
        private readonly UserKeyResolver $userKeys,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', self::PRIORITY],
            LoginSuccessEvent::class => 'onLoginOrLogout',
            LogoutEvent::class => 'onLoginOrLogout',
        ];
    }

    /**
     * Whether the challenge guards the route: the admin and agent panels and
     * the admin plugin endpoints, not the challenge itself, the customer
     * area, the widget or the token API.
     */
    public static function guards(string $route): bool
    {
        return str_starts_with($route, 'escalated.admin.')
            || str_starts_with($route, 'escalated.agent.')
            || 'escalated.plugins.endpoint' === $route;
    }

    public function onLoginOrLogout(LoginSuccessEvent|LogoutEvent $event): void
    {
        // Only a session that already existed can hold a pass; touching none
        // keeps stateless firewalls (token or JWT sign-ins) session-free.
        $request = $event->getRequest();
        if ($request->hasPreviousSession()) {
            TwoFactorSession::forget($request->getSession());
        }
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->features->twoFactor()) {
            return;
        }

        $request = $event->getRequest();
        if (!self::guards((string) $request->attributes->get('_route', '')) || !$request->hasSession()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof UserInterface || $user instanceof ApiTokenUser) {
            return;
        }

        $userKey = $this->userKeys->resolve($user);
        if (null === $userKey) {
            return;
        }

        $session = $request->getSession();
        if (null !== $this->twoFactor->confirmedFor($userKey)) {
            if (!TwoFactorSession::passed($session, $userKey)) {
                $this->refuse($event, 'escalated.two-factor.challenge', 'Two-factor authentication is required.');
            }

            return;
        }

        if ($this->twoFactor->required()
            && !str_starts_with((string) $request->attributes->get('_route'), 'escalated.admin.two-factor.')
            && ($this->security->isGranted(EnsureAgentVoter::ATTRIBUTE) || $this->security->isGranted(EnsureAdminVoter::ATTRIBUTE))) {
            $this->refuse($event, 'escalated.admin.two-factor.index', 'Set up two-factor authentication to continue.');
        }
    }

    /**
     * Answer instead of the controller. Later controller listeners (the route
     * permission check among them) do not run: a user who still owes the
     * second factor is sent on, not told what they may not do.
     */
    private function refuse(ControllerEvent $event, string $route, string $message): void
    {
        $response = $this->deny($event->getRequest(), $route, $message);
        $event->setController(static fn (): Response => $response);
        $event->stopPropagation();
    }

    private function deny(Request $request, string $route, string $message): Response
    {
        if (self::wantsJson($request)) {
            return new JsonResponse(['message' => $message, 'two_factor_required' => true], Response::HTTP_FORBIDDEN);
        }

        if ($request->isMethod('GET') && $request->hasSession()) {
            TwoFactorSession::rememberIntended($request->getSession(), $request->getRequestUri());
        }

        return new RedirectResponse(
            $this->urls->generate($route),
            $request->isMethod('GET') || $request->isMethod('HEAD') ? Response::HTTP_FOUND : Response::HTTP_SEE_OTHER,
        );
    }

    /** A JSON client, not a page (Inertia visits send X-Inertia and ask for HTML). */
    public static function wantsJson(Request $request): bool
    {
        return !$request->headers->has('X-Inertia') && 'json' === $request->getPreferredFormat();
    }
}
