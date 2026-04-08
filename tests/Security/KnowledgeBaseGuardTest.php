<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Security;

use Escalated\Symfony\Security\KnowledgeBaseGuard;
use Escalated\Symfony\Service\KnowledgeBaseSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class KnowledgeBaseGuardTest extends TestCase
{
    private TokenStorageInterface&MockObject $tokenStorage;

    protected function setUp(): void
    {
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
    }

    private function createEvent(string $routeName): RequestEvent
    {
        $request = new Request();
        $request->attributes->set('_route', $routeName);
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testIgnoresNonKbRoutes(): void
    {
        $settings = new KnowledgeBaseSettings(enabled: false);
        $guard = new KnowledgeBaseGuard($settings, $this->tokenStorage);

        $event = $this->createEvent('escalated.agent.tickets.index');
        $guard->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testBlocksKbRoutesWhenDisabled(): void
    {
        $settings = new KnowledgeBaseSettings(enabled: false);
        $guard = new KnowledgeBaseGuard($settings, $this->tokenStorage);

        $event = $this->createEvent('escalated.kb.articles.index');
        $guard->onKernelRequest($event);

        $this->assertNotNull($event->getResponse());
        $this->assertSame(Response::HTTP_NOT_FOUND, $event->getResponse()->getStatusCode());
    }

    public function testAllowsPublicAccessWhenEnabled(): void
    {
        $settings = new KnowledgeBaseSettings(enabled: true, publicAccess: true);
        $guard = new KnowledgeBaseGuard($settings, $this->tokenStorage);

        $event = $this->createEvent('escalated.kb.articles.show');
        $guard->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testRequiresAuthWhenNotPublic(): void
    {
        $settings = new KnowledgeBaseSettings(enabled: true, publicAccess: false);
        $this->tokenStorage->method('getToken')->willReturn(null);

        $guard = new KnowledgeBaseGuard($settings, $this->tokenStorage);

        $event = $this->createEvent('escalated.kb.articles.show');
        $guard->onKernelRequest($event);

        $this->assertNotNull($event->getResponse());
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()->getStatusCode());
    }

    public function testAllowsAuthenticatedUserWhenNotPublic(): void
    {
        $settings = new KnowledgeBaseSettings(enabled: true, publicAccess: false);

        $user = $this->createMock(UserInterface::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $this->tokenStorage->method('getToken')->willReturn($token);

        $guard = new KnowledgeBaseGuard($settings, $this->tokenStorage);

        $event = $this->createEvent('escalated.kb.articles.show');
        $guard->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testCustomRoutePrefix(): void
    {
        $settings = new KnowledgeBaseSettings(enabled: false);
        $guard = new KnowledgeBaseGuard($settings, $this->tokenStorage, 'myapp.kb.');

        $this->assertSame('myapp.kb.', $guard->getKbRoutePrefix());

        $event = $this->createEvent('myapp.kb.articles.index');
        $guard->onKernelRequest($event);

        $this->assertNotNull($event->getResponse());
        $this->assertSame(Response::HTTP_NOT_FOUND, $event->getResponse()->getStatusCode());
    }
}
