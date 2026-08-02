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
 * Resolves an `Authorization: Bearer <token>` header on the bundle's `/api`
 * routes to an authenticated identity, mirroring the AuthenticateApiToken
 * middleware in escalated-laravel.
 *
 * Implemented as a kernel.request listener (the same shape as
 * KnowledgeBaseGuard) so it integrates with the host firewall's token storage
 * without requiring the host to register a custom firewall authenticator:
 *
 *  - Only acts on routes whose name starts with the api prefix.
 *  - No bearer header  -> no-op (session / existing auth still applies).
 *  - Unknown / revoked -> 401 "Invalid token."
 *  - Expired           -> 401 "Token has expired."
 *  - Valid             -> places a PreAuthenticatedToken (roles derived from
 *                         the token's abilities) on the token storage and
 *                         records throttled usage.
 */
class ApiTokenAuthenticator
{
    public function __construct(
        private readonly ApiTokenService $service,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly string $apiRoutePrefix = 'escalated.api.',
        private readonly string $firewallName = 'main',
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
            // Leave session-authenticated / public API access untouched.
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

        $this->service->touchLastUsed($apiToken, $request->getClientIp());
        $request->attributes->set('escalated_api_token', $apiToken);
    }

    public function getApiRoutePrefix(): string
    {
        return $this->apiRoutePrefix;
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
