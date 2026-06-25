<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Article;
use Escalated\Symfony\Service\KnowledgeBaseService;
use PHPUnit\Framework\TestCase;

final class KnowledgeBaseServiceTest extends TestCase
{
    private function service(): KnowledgeBaseService
    {
        return new KnowledgeBaseService($this->createMock(EntityManagerInterface::class));
    }

    public function testSlugify(): void
    {
        $service = $this->service();

        self::assertSame('hello-world', $service->slugify('Hello World'));
        self::assertSame('getting-started-guide', $service->slugify('  Getting Started: Guide!  '));
        self::assertSame('a-b-c', $service->slugify('a / b / c'));
        self::assertSame('v1-2-release', $service->slugify('v1.2 release'));
    }

    public function testArticleStatusHelpers(): void
    {
        $draft = new Article();
        self::assertFalse($draft->isPublished());
        self::assertSame(Article::STATUS_DRAFT, $draft->getStatus());

        $published = (new Article())->setStatus(Article::STATUS_PUBLISHED);
        self::assertTrue($published->isPublished());
    }

    public function testArticleCounters(): void
    {
        $article = new Article();
        self::assertSame(0, $article->getViewCount());

        $article->incrementViews();
        $article->incrementViews();
        $article->markHelpful();
        $article->markNotHelpful();
        $article->markNotHelpful();

        self::assertSame(2, $article->getViewCount());
        self::assertSame(1, $article->getHelpfulCount());
        self::assertSame(2, $article->getNotHelpfulCount());
    }
}
