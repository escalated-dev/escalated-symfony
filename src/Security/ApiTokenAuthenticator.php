<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Escalated\Symfony\Service\ApiTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Requires an authenticated principal on the bundle's `/api` routes and
 * resolves an `Authorization: Bearer <token>` header to one, mirroring the
 * AuthenticateApiToken middleware in escalated-laravel.
 *
 * Implemented as a kernel.request listener so it works behind the host's own
 * firewall without the host registering a custom authenticator. It must run
 * AFTER the firewall (priority 8): the firewall's context listener resets the
 * token storage to the session's token, which would discard a token set
 * earlier, and the session user is only known once the firewall has run.
 *
 *  - Only acts on routes whose name starts with the api prefix.
 *  - Public API routes (the knowledge base) -> no bearer is required; the
 *                         KnowledgeBaseGuard decides.
 *  - No bearer header  -> 401 "Unauthenticated." unless the firewall already
 *                         authenticated a user (a signed-in session).
 *  - Unknown / revoked -> 401 "Invalid token."
 *  - Expired           -> 401 "Token has expired."
 *  - Valid             -> places a PreAuthenticatedToken (roles derived from
 *                         the token's abilities) on the token storage for this
 *                         request only, and records throttled usage.
 *
 * Authorization (agent / admin access) is the controllers' job, through the
 * ESCALATED_AGENT and ESCALATED_ADMIN voters.
 */
class ApiTokenAuthenticator
{
    /**
     * @param list<string> $publicRoutePrefixes api routes that need no credentials
     */
    public function __construct(
        private readonly ApiTokenService $service,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly string $apiRoutePrefix = 'escalated.api.',
        private readonly string $firewallName = 'main',
        private readonly array $publicRoutePrefixes = ['escalated.api.kb.'],
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $routeName = (string) $request->attributes->get('_route', '');
        if (!str_starts_with($routeName, $this->apiRoutePrefix)) {
            return;
        }

        $plainText = $this->extractToken($request);
        if (null === $plainText) {
            if (!$this->isPublicRoute($routeName) && !$this->hasAuthenticatedUser()) {
                $event->setResponse(new JsonResponse(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED));
            }

            return;
        }

        $apiToken = $this->service->findByPlainText($plainText);
        if (null === $apiToken) {
            $event->setResponse(new JsonResponse(['message' => 'Invalid token.'], Response::HTTP_UNAUTHORIZED));

            return;
        }

        if ($apiToken->isExpired()) {
            $event->setResponse(new JsonResponse(['message' => 'Token has expired.'], Response::HTTP_UNAUTHORIZED));

            return;
        }

        $roles = $this->rolesForAbilities($apiToken->getAbilities() ?? []);
        $user = new ApiTokenUser($apiToken->getUserId(), $roles);

        $this->tokenStorage->setToken(new PreAuthenticatedToken($user, $this->firewallName, $roles));

        // A bearer token authenticates one request. Without this, a stateful
        // host firewall writes the token into the session on the way out, and
        // the session keeps working after the token is revoked.
        $request->attributes->remove('_security_firewall_run');

        $this->service->touchLastUsed($apiToken, $request->getClientIp());
        $request->attributes->set('escalated_api_token', $apiToken);
    }

    public function getApiRoutePrefix(): string
    {
        return $this->apiRoutePrefix;
    }

    private function isPublicRoute(string $routeName): bool
    {
        foreach ($this->publicRoutePrefixes as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function hasAuthenticatedUser(): bool
    {
        return null !== $this->tokenStorage->getToken()?->getUser();
    }

    private function extractToken(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return '' !== $token ? $token : null;
    }

    /**
     * Map token abilities to security roles. An `admin` (or `*`) ability implies
     * agent access, matching the Laravel middleware which requires the agent
     * gate for both the `agent` and `admin` abilities.
     *
     * @param string[] $abilities
     *
     * @return string[]
     */
    private function rolesForAbilities(array $abilities): array
    {
        $roles = ['ROLE_USER'];
        $wildcard = in_array('*', $abilities, true);

        if ($wildcard || in_array('admin', $abilities, true)) {
            $roles[] = 'ROLE_ESCALATED_ADMIN';
            $roles[] = 'ROLE_ESCALATED_AGENT';
        }

        if ($wildcard || in_array('agent', $abilities, true)) {
            $roles[] = 'ROLE_ESCALATED_AGENT';
        }

        return array_values(array_unique($roles));
    }
}
