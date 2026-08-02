<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Security;

use Escalated\Symfony\Entity\ApiToken;
use Escalated\Symfony\Security\ApiTokenAuthenticator;
use Escalated\Symfony\Security\ApiTokenUser;
use Escalated\Symfony\Service\ApiTokenService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class ApiTokenAuthenticatorTest extends TestCase
{
    private ApiTokenService&MockObject $service;
    private TokenStorageInterface&MockObject $tokenStorage;

    protected function setUp(): void
    {
        $this->service = $this->createMock(ApiTokenService::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
    }

    private function authenticator(): ApiTokenAuthenticator
    {
        return new ApiTokenAuthenticator($this->service, $this->tokenStorage);
    }

    private function createEvent(string $routeName, ?string $authHeader = null): RequestEvent
    {
        $request = new Request();
        $request->attributes->set('_route', $routeName);
        if (null !== $authHeader) {
            $request->headers->set('Authorization', $authHeader);
        }

        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function token(array $abilities, ?\DateTimeImmutable $expiresAt = null): ApiToken
    {
        return (new ApiToken())
            ->setUserId('42')
            ->setAbilities($abilities)
            ->setExpiresAt($expiresAt);
    }

    public function testIgnoresNonApiRoutes(): void
    {
        $this->service->expects($this->never())->method('findByPlainText');
        $this->tokenStorage->expects($this->never())->method('setToken');

        $event = $this->createEvent('escalated.agent.tickets.index', 'Bearer abc');
        $this->authenticator()->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testIgnoresApiRouteWithoutBearer(): void
    {
        $this->service->expects($this->never())->method('findByPlainText');
        $this->tokenStorage->expects($this->never())->method('setToken');

        $event = $this->createEvent('escalated.api.auth.me');
        $this->authenticator()->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testValidTokenAuthenticatesRequest(): void
    {
        $token = $this->token(['*']);
        $this->service->method('findByPlainText')->with('secret-plain')->willReturn($token);
        $this->service->expects($this->once())->method('touchLastUsed')->with($token, $this->anything());

        $captured = null;
        $this->tokenStorage->expects($this->once())
            ->method('setToken')
            ->with($this->callback(function (TokenInterface $t) use (&$captured): bool {
                $captured = $t;

                return true;
            }));

        $event = $this->createEvent('escalated.api.auth.me', 'Bearer secret-plain');
        $this->authenticator()->onKernelRequest($event);

        $this->assertNull($event->getResponse());
        $this->assertInstanceOf(ApiTokenUser::class, $captured->getUser());
        $this->assertSame('42', $captured->getUser()->getUserIdentifier());
        $this->assertContains('ROLE_ESCALATED_ADMIN', $captured->getRoleNames());
        $this->assertContains('ROLE_ESCALATED_AGENT', $captured->getRoleNames());
        $this->assertSame($token, $event->getRequest()->attributes->get('escalated_api_token'));
    }

    public function testInvalidTokenIsRejected(): void
    {
        $this->service->method('findByPlainText')->willReturn(null);
        $this->tokenStorage->expects($this->never())->method('setToken');

        $event = $this->createEvent('escalated.api.auth.me', 'Bearer nope');
        $this->authenticator()->onKernelRequest($event);

        $this->assertNotNull($event->getResponse());
        $this->assertSame(401, $event->getResponse()->getStatusCode());
        $this->assertStringContainsString('Invalid token', (string) $event->getResponse()->getContent());
    }

    public function testRevokedTokenIsRejected(): void
    {
        // A revoked token has been deleted, so the hash lookup misses.
        $this->service->method('findByPlainText')->willReturn(null);
        $this->tokenStorage->expects($this->never())->method('setToken');

        $event = $this->createEvent('escalated.api.tickets.index', 'Bearer revoked');
        $this->authenticator()->onKernelRequest($event);

        $this->assertSame(401, $event->getResponse()->getStatusCode());
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->service->method('findByPlainText')->willReturn(
            $this->token(['*'], new \DateTimeImmutable('-1 second')),
        );
        $this->tokenStorage->expects($this->never())->method('setToken');

        $event = $this->createEvent('escalated.api.auth.me', 'Bearer expired');
        $this->authenticator()->onKernelRequest($event);

        $this->assertSame(401, $event->getResponse()->getStatusCode());
        $this->assertStringContainsString('expired', (string) $event->getResponse()->getContent());
    }

    public function testAgentAbilityMapsToAgentRoleOnly(): void
    {
        $this->service->method('findByPlainText')->willReturn($this->token(['agent']));

        $captured = null;
        $this->tokenStorage->method('setToken')->willReturnCallback(
            function (TokenInterface $t) use (&$captured): void {
                $captured = $t;
            },
        );

        $event = $this->createEvent('escalated.api.auth.me', 'Bearer agent-token');
        $this->authenticator()->onKernelRequest($event);

        $this->assertContains('ROLE_ESCALATED_AGENT', $captured->getRoleNames());
        $this->assertNotContains('ROLE_ESCALATED_ADMIN', $captured->getRoleNames());
    }

    public function testAdminAbilityImpliesAgentRole(): void
    {
        $this->service->method('findByPlainText')->willReturn($this->token(['admin']));

        $captured = null;
        $this->tokenStorage->method('setToken')->willReturnCallback(
            function (TokenInterface $t) use (&$captured): void {
                $captured = $t;
            },
        );

        $event = $this->createEvent('escalated.api.auth.me', 'Bearer admin-token');
        $this->authenticator()->onKernelRequest($event);

        $this->assertContains('ROLE_ESCALATED_ADMIN', $captured->getRoleNames());
        $this->assertContains('ROLE_ESCALATED_AGENT', $captured->getRoleNames());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $this->service->expects($this->never())->method('findByPlainText');

        $request = new Request();
        $request->attributes->set('_route', 'escalated.api.auth.me');
        $request->headers->set('Authorization', 'Bearer abc');
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );

        $this->authenticator()->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }
}
