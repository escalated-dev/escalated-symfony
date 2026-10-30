<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A refusal or a failure inside the panel stays inside the panel.
 *
 * An Inertia visit that gets an HTML or JSON error back has nowhere to put it:
 * the client opens it in a modal over the screen the user was on. So an
 * Inertia visit to one of Escalated's routes that ends in 403, 404, 419, 429,
 * 500 or 503 gets the shared frontend's Escalated/Error page, with the props
 * Rails and Laravel send: the status and a generic message. Full page loads,
 * JSON clients, the API and widget routes and the host's own routes are left
 * alone.
 */
final class InertiaErrorPageTest extends EscalatedWebTestCase
{
    private const INERTIA = ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml'];

    private const MESSAGES = [
        403 => 'You are not authorized to perform this action.',
        404 => 'The requested resource was not found.',
        419 => 'Your session has expired. Refresh the page and try again.',
        429 => 'Too many requests. Wait a moment and try again.',
        500 => 'Something went wrong on our end.',
        503 => 'The service is temporarily unavailable. Try again shortly.',
    ];

    public function testARefusedInertiaVisitGetsTheErrorPage(): void
    {
        $client = $this->signedInAgent();

        $client->request('GET', '/support/admin/settings', server: self::INERTIA);

        $this->assertErrorPage($client, 403);
    }

    public function testAnInertiaVisitToAMissingTicketGetsTheErrorPage(): void
    {
        $client = $this->signedInAgent();

        $client->request('GET', '/support/agent/tickets/NOPE-404', server: self::INERTIA);

        $this->assertErrorPage($client, 404);
    }

    public function testThrownFailuresGetTheErrorPage(): void
    {
        $client = static::clientWithSchema();

        foreach ([429, 500, 503] as $status) {
            $client->request('GET', '/support/error-probe/thrown/'.$status, server: self::INERTIA);
            $this->assertErrorPage($client, $status);
        }
    }

    public function testReturnedErrorResponsesGetTheErrorPage(): void
    {
        $client = static::clientWithSchema();

        foreach (array_keys(self::MESSAGES) as $status) {
            $client->request('GET', '/support/error-probe/returned/'.$status, server: self::INERTIA);
            $this->assertErrorPage($client, $status);
        }
    }

    public function testSendsOnlyTheStatusAndTheMessage(): void
    {
        $client = $this->signedInAgent();

        $client->request('GET', '/support/admin/settings', server: self::INERTIA);

        $page = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['status', 'message'], array_keys($page['props']));
    }

    public function testLeavesA500ToTheDebugPageWhileDebugIsOn(): void
    {
        $client = static::clientWithSchema(['debug' => true]);

        $client->request('GET', '/support/error-probe/thrown/500', server: self::INERTIA);

        self::assertResponseStatusCodeSame(500);
        // Symfony's exception page (its stack trace quotes this file, so look
        // at the shape of the response rather than for the page name).
        self::assertStringStartsWith('text/html', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertNull(json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testLeavesAFullPageLoadAlone(): void
    {
        $client = $this->signedInAgent();

        $client->request('GET', '/support/admin/settings');

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Escalated/Error', (string) $client->getResponse()->getContent());
    }

    public function testLeavesAJsonRequestAlone(): void
    {
        $client = static::clientWithSchema();

        $client->request('GET', '/support/error-probe/returned/404', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'upstream detail'], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testLeavesAHostRouteAlone(): void
    {
        $client = static::clientWithSchema();

        $client->request('GET', '/host/missing', server: self::INERTIA);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Escalated/Error', (string) $client->getResponse()->getContent());
    }

    public function testLeavesTheJsonApiAlone(): void
    {
        $client = static::clientWithSchema();

        $client->request('GET', '/support/api/v1/tickets', server: self::INERTIA);

        self::assertSame(401, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Escalated/Error', (string) $client->getResponse()->getContent());
    }

    public function testLeavesValidationRedirectsAlone(): void
    {
        $client = static::clientWithSchema();

        $client->request('GET', '/support/error-probe/returned/302', server: self::INERTIA);

        self::assertResponseStatusCodeSame(302);
    }

    public function testStaysOffWhileTheUiIsDisabled(): void
    {
        $client = static::clientWithSchema(['escalated' => ['ui_enabled' => false]]);

        $client->request('GET', '/support/error-probe/returned/404', server: self::INERTIA);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'upstream detail'], json_decode((string) $client->getResponse()->getContent(), true));
    }

    private function signedInAgent(): KernelBrowser
    {
        $client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $em = static::entityManager();
        $agent = new TestUser('agent@example.com');
        $em->persist($agent);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $agent->getId()));
        $em->flush();
        $client->loginUser($agent);

        return $client;
    }

    private function assertErrorPage(KernelBrowser $client, int $status): void
    {
        $response = $client->getResponse();
        $content = (string) $response->getContent();
        self::assertSame($status, $response->getStatusCode(), $content);
        $page = json_decode($content, true);
        self::assertIsArray($page, $content);
        self::assertSame('Escalated/Error', $page['component'] ?? null, $content);
        self::assertSame(['status' => $status, 'message' => self::MESSAGES[$status]], $page['props']);
        self::assertStringNotContainsString('upstream detail', $content);
        self::assertStringNotContainsString('escalated_secret_table', $content);
        self::assertStringNotContainsString('NOPE-404', $content);
    }
}
