<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Embed;

use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;

/**
 * A server-rendered (Twig) host, host type B of ADR
 * 2026-11-05-embeddable-admin: `root_view` names the host's admin template,
 * which holds the host chrome, the Inertia mount point and the admin menu
 * (Fixtures/templates/admin/escalated.html.twig, the template in
 * docs/embedding-admin.md). Only embedded responses use it.
 */
final class EmbedRootViewTest extends EmbedTestCase
{
    public function testEmbeddedPagesRenderInTheHostsRootViewWithTheMenu(): void
    {
        $this->boot(self::layoutEmbed(['root_view' => 'admin/escalated.html.twig', 'layout' => null]));
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin/support/tags');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('<header class="host-header">Acme Admin</header>', $html);
        self::assertStringContainsString('<a href="/admin/support/tags" class="active" aria-current="page">Tags</a>', $html);
        self::assertStringContainsString('<a href="/admin/support/reports">Reports</a>', $html);
        self::assertStringContainsString('<a href="/admin/support/settings">Settings</a>', $html);
        self::assertStringNotContainsString('data-root-view="default"', $html);

        $page = $this->page($html);
        self::assertSame('Escalated/Admin/Tags/Index', $page['component']);
        self::assertTrue($page['props']['escalated']['embed']['enabled']);
        self::assertNull($page['props']['escalated']['embed']['layout']);
        self::assertSame('escalated.admin.tags.index', $page['props']['escalated']['embed']['route']);
        self::assertSame('tags', $page['props']['escalated']['navigation'][9]['key']);

        // Every other page keeps the host's normal root view.
        $this->client->request('GET', '/support/agent/dashboard');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-root-view="default"', $html);
        self::assertStringNotContainsString('Acme Admin', $html);
        $page = $this->page($html);
        self::assertSame('Escalated/Agent/Dashboard', $page['component']);
        self::assertFalse($page['props']['escalated']['embed']['enabled']);
    }

    public function testTheDocsShowTheTemplateThisTestRenders(): void
    {
        $template = str_replace("\r\n", "\n", (string) file_get_contents(\dirname(__DIR__).'/Fixtures/templates/admin/escalated.html.twig'));
        $docs = (string) file_get_contents(\dirname(__DIR__, 3).'/docs/embedding-admin.md');

        self::assertStringContainsString("```twig\n".$template.'```', str_replace("\r\n", "\n", $docs));
    }

    public function testWithEmbeddingOffTheRendererIsUnchanged(): void
    {
        $this->client = static::clientWithSchema([
            'escalated' => ['user_class' => TestUser::class],
            'embed_host' => true,
        ]);
        $this->users();
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/support/admin/tags');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-root-view="default"', $html);
        $page = $this->page($html);
        self::assertSame('Escalated/Admin/Tags/Index', $page['component']);
        self::assertArrayNotHasKey('escalated', $page['props'], 'nothing is shared with the Inertia service while embedding is off');
        self::assertArrayNotHasKey('embed', $this->shared());
        self::assertArrayNotHasKey('navigation', $this->shared());
    }

    /**
     * @return array<string, mixed>
     */
    private function page(string $html): array
    {
        self::assertSame(1, preg_match('/<div id="app" data-page="([^"]*)"><\/div>/', $html, $match), 'the Inertia mount point');
        $page = json_decode(html_entity_decode($match[1], \ENT_QUOTES), true);
        self::assertIsArray($page);

        return $page;
    }
}
