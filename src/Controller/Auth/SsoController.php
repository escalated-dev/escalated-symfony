<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Auth;

use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\EnsureAdminVoter;
use Escalated\Symfony\Security\EnsureAgentVoter;
use Escalated\Symfony\Security\RequiresFeature;
use Escalated\Symfony\Security\SsoException;
use Escalated\Symfony\Service\SsoService;
use Escalated\Symfony\Service\SsoUserResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Sign-in through the SSO provider chosen in Admin > Settings > SSO
 * (ADR 2026-10-29-configurable-access-features), the port of
 * escalated-laravel's SsoController. Every route answers 404 unless
 * `escalated.access.sso` is on and the provider it serves is the chosen one.
 *
 * The user is signed in with Security::login() on the host firewall, so the
 * firewall's LoginSuccessEvent fires (the two-factor challenge still applies)
 * and the session is migrated. The bundle registers no CSRF protection on its
 * routes, so the IdP's cross-site POSTs to the ACS and the JWT callback need
 * no exemption; the signed, single-use message is what authenticates them.
 */
#[Route('/sso', name: 'escalated.sso.')]
#[RequiresFeature(AccessFeatures::SSO)]
class SsoController extends AbstractController
{
    use TargetPathTrait;

    private const OAUTH_STATE = '_escalated.sso.oauth_state';
    private const OAUTH_PKCE = '_escalated.sso.oauth_pkce';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SsoService $sso,
        private readonly SsoUserResolver $users,
        private readonly Security $security,
        #[Autowire('%escalated.sso.firewall%')]
        private readonly ?string $firewall = null,
        #[Autowire('%escalated.sso.authenticator%')]
        private readonly ?string $authenticator = null,
        #[Autowire('%escalated.sso.redirect_to%')]
        private readonly ?string $redirectTo = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(Request $request): Response
    {
        $provider = $this->sso->getProvider();
        if ('none' === $provider) {
            throw new NotFoundHttpException('Not Found');
        }

        try {
            return match ($provider) {
                'saml' => new RedirectResponse($this->sso->samlLoginUrl()),
                'oauth' => $this->startOauth($request),
                default => $this->startJwt(),
            };
        } catch (SsoException $e) {
            return $this->refuse($e);
        }
    }

    #[Route('/saml/metadata', name: 'saml.metadata', methods: ['GET'])]
    public function metadata(): Response
    {
        $this->requireProvider('saml');

        try {
            return new Response($this->sso->samlMetadata(), Response::HTTP_OK, ['Content-Type' => 'application/samlmetadata+xml']);
        } catch (SsoException $e) {
            $this->logger->warning('Escalated SSO: '.$e->getMessage());

            return new Response('SAML metadata is not available.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/saml/acs', name: 'saml.acs', methods: ['POST'])]
    public function samlAcs(Request $request): Response
    {
        $this->requireProvider('saml');

        return $this->signIn($request, fn (): array => $this->sso->validateSamlResponse((string) $request->request->get('SAMLResponse', '')));
    }

    #[Route('/oauth/callback', name: 'oauth.callback', methods: ['GET'])]
    public function oauthCallback(Request $request): Response
    {
        $this->requireProvider('oauth');

        // Single use: pulled before anything is checked.
        $session = $request->getSession();
        $state = (string) $session->remove(self::OAUTH_STATE);
        $verifier = (string) $session->remove(self::OAUTH_PKCE);

        return $this->signIn($request, function () use ($request, $state, $verifier): array {
            $error = $request->query->get('error');
            if (\is_string($error) && '' !== $error) {
                throw new SsoException(sprintf('OAuth refused: the provider returned %s.', $error));
            }

            $given = $request->query->get('state');
            if ('' === $state || !\is_string($given) || !hash_equals($state, $given)) {
                throw new SsoException('OAuth refused: missing or mismatched state.');
            }

            $code = $request->query->get('code');
            if (!\is_string($code) || '' === $code || '' === $verifier) {
                throw new SsoException('OAuth refused: no authorization code or PKCE verifier.');
            }

            return $this->sso->oauthIdentity($code, $verifier);
        });
    }

    #[Route('/jwt/callback', name: 'jwt.callback', methods: ['GET', 'POST'])]
    public function jwtCallback(Request $request): Response
    {
        $this->requireProvider('jwt');

        $token = $request->isMethod('POST') ? $request->request->get('jwt', $request->query->get('jwt', '')) : $request->query->get('jwt', '');

        return $this->signIn($request, fn (): array => $this->sso->validateJwtToken(\is_string($token) ? $token : ''));
    }

    private function startOauth(Request $request): Response
    {
        $provider = $this->sso->oauthProvider();
        $url = $provider->getAuthorizationUrl();

        $session = $request->getSession();
        $session->set(self::OAUTH_STATE, $provider->getState());
        $session->set(self::OAUTH_PKCE, $provider->getPkceCode());

        return new RedirectResponse($url);
    }

    private function startJwt(): Response
    {
        $config = $this->sso->getConfig();
        $url = trim('' !== $config['sso_login_url'] ? $config['sso_login_url'] : $config['sso_url']);
        if ('' === $url) {
            throw new SsoException('JWT sign-in has no login URL configured.');
        }

        $query = http_build_query(['return_to' => $this->sso->endpoints()['jwt_callback']]);

        return new RedirectResponse($url.(str_contains($url, '?') ? '&' : '?').$query);
    }

    /**
     * @param callable(): array{email: string, name: string, role: string} $identify
     */
    private function signIn(Request $request, callable $identify): Response
    {
        try {
            $user = $this->users->resolve($identify());
        } catch (SsoException $e) {
            return $this->refuse($e);
        }

        $firewall = $this->firewall ?? $this->security->getFirewallConfig($request)?->getName();

        try {
            // Through the firewall: LoginSuccessEvent fires, so the two-factor
            // challenge applies to this sign-in as to any other.
            $this->security->login($user, $this->authenticator, $firewall);
        } catch (AuthenticationException|\LogicException $e) {
            return $this->refuse(new SsoException('SSO refused: the firewall did not sign the user in: '.$e->getMessage(), 0, $e));
        }

        $session = $request->getSession();
        $session->migrate(true);

        $this->logger->info('Escalated SSO: signed in', ['user' => $user->getUserIdentifier(), 'provider' => $this->sso->getProvider()]);

        $target = null !== $firewall ? $this->getTargetPath($session, $firewall) : null;
        if (null !== $firewall) {
            $this->removeTargetPath($session, $firewall);
        }

        return new RedirectResponse(\is_string($target) && '' !== $target ? $target : $this->home(), Response::HTTP_FOUND);
    }

    private function home(): string
    {
        if (null !== $this->redirectTo) {
            return $this->redirectTo;
        }

        if ($this->isGranted(EnsureAgentVoter::ATTRIBUTE)) {
            return $this->generateUrl('escalated.agent.dashboard');
        }

        return $this->isGranted(EnsureAdminVoter::ATTRIBUTE)
            ? $this->generateUrl('escalated.admin.reports')
            : $this->generateUrl('escalated.customer.tickets.index');
    }

    private function refuse(SsoException $e): Response
    {
        $this->logger->warning('Escalated SSO: '.$e->getMessage());

        return new Response('Single sign-on failed. Contact your administrator.', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function requireProvider(string $provider): void
    {
        if ($this->sso->getProvider() !== $provider) {
            throw new NotFoundHttpException('Not Found');
        }
    }
}
