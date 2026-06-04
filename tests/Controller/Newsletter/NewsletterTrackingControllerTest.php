<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Controller\Newsletter;

use Escalated\Symfony\Controller\Newsletter\Public\NewsletterTrackingController;
use Escalated\Symfony\Service\Newsletter\NewsletterTracker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class NewsletterTrackingControllerTest extends TestCase
{
    public function testOpenPixelStripsImageExtensionAndAlwaysReturnsPng(): void
    {
        $tracker = $this->createMock(NewsletterTracker::class);
        $tracker->expects($this->once())->method('recordOpen')->with('abc123');
        $controller = new NewsletterTrackingController($tracker, true);

        $response = $controller->open('abc123.gif');

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame(68, strlen($response->getContent()));
    }

    public function testClickRejectsUnsafeDestinations(): void
    {
        $tracker = $this->createMock(NewsletterTracker::class);
        $tracker->expects($this->never())->method('recordClick');
        $controller = new NewsletterTrackingController($tracker, true);

        $response = $controller->click('abc123', new Request(['u' => rtrim(strtr(base64_encode('javascript:alert(1)'), '+/', '-_'), '=')]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testClickRedirectsToDecodedHttpUrl(): void
    {
        $tracker = $this->createMock(NewsletterTracker::class);
        $tracker->expects($this->once())->method('recordClick')->with('abc123', 'https://example.test/path');
        $controller = new NewsletterTrackingController($tracker, true);
        $encoded = rtrim(strtr(base64_encode('https://example.test/path'), '+/', '-_'), '=');

        $response = $controller->click('abc123', new Request(['u' => $encoded]));

        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertSame('https://example.test/path', $response->headers->get('Location'));
    }
}
