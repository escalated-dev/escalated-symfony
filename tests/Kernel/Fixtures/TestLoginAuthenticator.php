<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * A host sign-in through the firewall, the way a real login form signs in:
 * POST /test-login with `email` authenticates that user and dispatches
 * LoginSuccessEvent (KernelBrowser::loginUser() dispatches nothing). The
 * session is migrated, keeping its attributes, as with a host's form login.
 */
final class TestLoginAuthenticator extends AbstractAuthenticator
{
    public const PATH = '/test-login';

    public function supports(Request $request): ?bool
    {
        return self::PATH === $request->getPathInfo() && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        return new SelfValidatingPassport(new UserBadge((string) $request->request->get('email')));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new Response('signed in');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new Response('refused', Response::HTTP_UNAUTHORIZED);
    }
}
