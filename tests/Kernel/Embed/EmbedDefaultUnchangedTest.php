<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Embed;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Component\Routing\RouterInterface;

/**
 * The embeddable admin (ADR 2026-11-05-embeddable-admin) is off by default,
 * and while it is off nothing changes: the route table is the one master had
 * before the feature (tests/Fixtures/embed/default-routes.json), the admin is
 * served at /support/admin, and responses carry no embed props and no framing
 * headers.
 *
 * ESCALATED_WRITE_EMBED_SNAPSHOT=1 rewrites the snapshot; it was written once,
 * from master, before the feature.
 */
final class EmbedDefaultUnchangedTest extends EscalatedWebTestCase
{
    public function testTheRouteTableIsTheSnapshotTakenBeforeTheFeature(): void
    {
        $live = [];
        foreach (RouteTable::configurations() as $key => $config) {
            static::ensureKernelShutdown();
            static::bootKernel(['escalated' => $config]);
            $router = static::getContainer()->get('router');
            \assert($router instanceof RouterInterface);
            $live[$key] = RouteTable::normalised(RouteTable::of($router));
        }

        if ('1' === getenv('ESCALATED_WRITE_EMBED_SNAPSHOT')) {
            file_put_contents(RouteTable::FIXTURE, RouteTable::encode($live));
        }

        $snapshot = RouteTable::snapshot();
        self::assertSame(array_keys($snapshot), array_keys($live));
        foreach ($snapshot as $key => $routes) {
            self::assertNotEmpty($routes);
            self::assertSame($routes, $live[$key], $key.': the route table changed with embedding off');
        }
    }

    public function testTheAdminIsServedAtItsStandaloneUrlWithNoEmbedPropsOrFramingHeaders(): void
    {
        $client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $em = static::entityManager();
        $admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $agent = new TestUser('agent@example.com');
        $em->persist($admin);
        $em->persist($agent);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $agent->getId()));
        $em->flush();

        $client->loginUser($admin);
        $client->request('GET', '/support/admin/tags', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Escalated/Admin/Tags/Index', $page['component']);
        $shared = $client->getRequest()->attributes->get('_escalated_shared');
        self::assertIsArray($shared);
        self::assertArrayNotHasKey('embed', $shared);
        self::assertArrayNotHasKey('navigation', $shared);
        self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));
        self::assertFalse($client->getResponse()->headers->has('X-Frame-Options'));

        $client->request('GET', '/admin/support/tags');
        self::assertResponseStatusCodeSame(404);

        // The admin gate is in place: an agent who is not an admin is refused.
        $client->loginUser($agent);
        $client->request('GET', '/support/admin/tags', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseStatusCodeSame(403);
    }
}
