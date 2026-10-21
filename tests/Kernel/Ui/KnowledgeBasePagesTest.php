<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\Article;
use Escalated\Symfony\Entity\ArticleCategory;
use Escalated\Symfony\Service\KnowledgeBaseSettings;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * The knowledge base pages against what the shared pages read and post
 * (escalated-laravel's Admin\ArticleController and
 * Customer\KnowledgeBaseController). Articles/Index read a paginator
 * (`articles.data`, `links`) and got a plain list; there was no Articles/Form
 * page and no reader pages; and the pages call `kb-articles.*`,
 * `kb-categories.*` and `customer.kb.*`, which did not exist.
 */
final class KnowledgeBasePagesTest extends EscalatedWebTestCase
{
    private const PAGE_ROUTES = [
        'escalated.admin.kb-articles.index', 'escalated.admin.kb-articles.create', 'escalated.admin.kb-articles.store',
        'escalated.admin.kb-articles.edit', 'escalated.admin.kb-articles.update', 'escalated.admin.kb-articles.destroy',
        'escalated.admin.kb-categories.store', 'escalated.admin.kb-categories.update', 'escalated.admin.kb-categories.destroy',
        'escalated.customer.kb.index', 'escalated.customer.kb.show', 'escalated.customer.kb.feedback',
        // kept for host links
        'escalated.admin.kb_articles.index', 'escalated.admin.kb_articles.delete', 'escalated.admin.kb_categories.index', 'escalated.admin.kb_categories.delete',
    ];

    private KernelBrowser $client;
    private ArticleCategory $guides;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $em = static::entityManager();
        $this->guides = (new ArticleCategory())->setName('Guides')->setSlug('guides')->setDescription('How-tos');
        $em->persist($this->guides);
        $em->flush();
    }

    public function testEveryRouteThePagesCallExists(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        self::assertSame([], array_values(array_filter(self::PAGE_ROUTES, static fn (string $n): bool => null === $router->getRouteCollection()->get($n))));
    }

    public function testAdminArticlesIndexIsAPaginatorAndTheFormCreatesAndEdits(): void
    {
        $this->loginAdmin();

        $page = $this->visit('/support/admin/kb-articles/create');
        self::assertSame('Escalated/Admin/KnowledgeBase/Articles/Form', $page['component']);
        self::assertSame(['article' => null, 'categories' => [['id' => $this->guides->getId(), 'name' => 'Guides']]], $page['props']);

        $this->send('POST', '/support/admin/kb-articles', ['title' => 'Reset your password', 'slug' => '', 'body' => '<p>Click reset.</p>', 'status' => 'published', 'category_id' => $this->guides->getId()]);
        self::assertResponseRedirects('/support/admin/kb-articles');
        $article = static::entityManager()->getRepository(Article::class)->findOneBy(['slug' => 'reset-your-password']);
        self::assertNotNull($article);
        self::assertNotNull($article->getPublishedAt());
        self::assertNotNull($article->getAuthorId());

        $page = $this->visit('/support/admin/kb-articles?status=published');
        self::assertSame('Escalated/Admin/KnowledgeBase/Articles/Index', $page['component']);
        foreach (['data', 'links', 'last_page', 'total'] as $key) {
            self::assertArrayHasKey($key, $page['props']['articles']);
        }
        $row = $page['props']['articles']['data'][0];
        foreach (['id', 'title', 'status', 'view_count', 'helpful_count', 'created_at'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertSame('Guides', $row['category']['name']);
        self::assertSame(['status' => 'published'], $page['props']['filters']);

        $page = $this->visit('/support/admin/kb-articles/'.$article->getId().'/edit');
        foreach (['id', 'title', 'slug', 'body', 'status', 'category_id'] as $key) {
            self::assertArrayHasKey($key, $page['props']['article']);
        }
        self::assertSame('<p>Click reset.</p>', $page['props']['article']['body']);

        $this->send('POST', '/support/admin/kb-articles', ['title' => '', 'status' => 'live', 'slug' => 'reset-your-password']);
        $errors = $this->client->getRequest()->getSession()->getFlashBag()->peek('errors');
        self::assertSame(['title', 'status', 'slug'], array_keys(end($errors) ?: []));
    }

    public function testReadersSeePublishedArticlesAndLeaveFeedback(): void
    {
        $published = $this->article('How to export', 'how-to-export', Article::STATUS_PUBLISHED);
        $this->article('Related tip', 'related-tip', Article::STATUS_PUBLISHED);
        $this->article('Secret draft', 'secret-draft', Article::STATUS_DRAFT);

        $page = $this->visit('/support/kb');
        self::assertSame('Escalated/Customer/KnowledgeBase/Index', $page['component']);
        self::assertSame([['id' => $this->guides->getId(), 'name' => 'Guides', 'slug' => 'guides', 'description' => 'How-tos', 'articles_count' => 2]], $page['props']['categories']);
        self::assertEqualsCanonicalizing(['How to export', 'Related tip'], array_column($page['props']['articles']['data'], 'title'));
        $row = $page['props']['articles']['data'][0];
        foreach (['id', 'title', 'slug', 'category', 'view_count'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertTrue($page['props']['feedbackEnabled']);

        $page = $this->visit('/support/kb?search=EXPORT');
        self::assertSame(['How to export'], array_column($page['props']['articles']['data'], 'title'));
        self::assertSame(['search' => 'EXPORT'], $page['props']['filters']);

        $page = $this->visit('/support/kb/how-to-export');
        self::assertSame('Escalated/Customer/KnowledgeBase/Article', $page['component']);
        foreach (['title', 'body', 'category', 'published_at', 'view_count', 'slug'] as $key) {
            self::assertArrayHasKey($key, $page['props']['article']);
        }
        self::assertSame(['id' => $this->guides->getId(), 'name' => 'Guides'], $page['props']['article']['category']);
        self::assertSame(['Related tip'], array_column($page['props']['related'], 'title'));

        $this->client->request('GET', '/support/kb/secret-draft');
        self::assertResponseStatusCodeSame(404);

        $this->send('POST', '/support/kb/how-to-export/feedback', ['helpful' => true]);
        self::assertResponseRedirects();
        static::entityManager()->clear();
        $published = static::entityManager()->getRepository(Article::class)->find($published->getId());
        self::assertSame(1, $published->getHelpfulCount());
        self::assertSame(1, $published->getViewCount());
    }

    public function testAPrivateOrDisabledKnowledgeBaseIsClosed(): void
    {
        $settings = static::getContainer()->get(KnowledgeBaseSettings::class);
        $settings->setPublicAccess(false);
        $this->client->request('GET', '/support/kb');
        // Access denied to an anonymous visitor: the host firewall's entry
        // point answers (401 here, a login redirect in most hosts).
        self::assertResponseStatusCodeSame(401);

        $settings->setEnabled(false);
        $this->client->request('GET', '/support/kb');
        self::assertResponseStatusCodeSame(404);
    }

    private function article(string $title, string $slug, string $status): Article
    {
        $article = (new Article())->setTitle($title)->setSlug($slug)->setBody('Body of '.$title)->setStatus($status)->setCategory($this->guides);
        if (Article::STATUS_PUBLISHED === $status) {
            $article->setPublishedAt(new \DateTimeImmutable());
        }
        $em = static::entityManager();
        $em->persist($article);
        $em->flush();

        return $article;
    }

    private function loginAdmin(): void
    {
        $admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::entityManager();
        $em->persist($admin);
        $em->flush();
        $this->client->loginUser($admin);
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
        ], content: (string) json_encode($body));
    }
}
