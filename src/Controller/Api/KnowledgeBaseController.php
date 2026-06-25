<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Api;

use Escalated\Symfony\Entity\Article;
use Escalated\Symfony\Entity\ArticleCategory;
use Escalated\Symfony\Service\KnowledgeBaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public knowledge-base read endpoints for the general JSON API (consumed by
 * the Flutter app and integrations). Only published articles are exposed.
 */
#[Route('/api/v1/kb', name: 'escalated.api.kb.')]
class KnowledgeBaseController extends AbstractController
{
    public function __construct(
        private readonly KnowledgeBaseService $kb,
    ) {
    }

    #[Route('/articles', name: 'articles', methods: ['GET'])]
    public function articles(Request $request): JsonResponse
    {
        $search = $request->query->get('search');
        $categoryId = $request->query->get('category');

        $articles = $this->kb->searchArticles(
            null !== $search ? (string) $search : null,
            Article::STATUS_PUBLISHED,
            null !== $categoryId && '' !== $categoryId ? (int) $categoryId : null,
        );

        return $this->json(['data' => array_map([$this, 'summary'], $articles)]);
    }

    #[Route('/categories', name: 'categories', methods: ['GET'])]
    public function categories(): JsonResponse
    {
        return $this->json([
            'data' => array_map([$this, 'category'], $this->kb->orderedCategories()),
        ]);
    }

    #[Route('/articles/{slug}', name: 'article', methods: ['GET'])]
    public function article(string $slug): JsonResponse
    {
        $article = $this->kb->findPublishedBySlug($slug);
        if (null === $article) {
            return $this->json(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->kb->recordView($article);

        $data = $this->summary($article);
        $data['body'] = $article->getBody();
        $data['related'] = array_map(
            static fn (Article $a): array => ['slug' => $a->getSlug(), 'title' => $a->getTitle()],
            $this->kb->relatedArticles($article),
        );

        return $this->json(['data' => $data]);
    }

    /** @return array<string, mixed> */
    private function summary(Article $article): array
    {
        return [
            'id' => $article->getId(),
            'slug' => $article->getSlug(),
            'title' => $article->getTitle(),
            'category_id' => $article->getCategory()?->getId(),
            'view_count' => $article->getViewCount(),
            'helpful_count' => $article->getHelpfulCount(),
            'not_helpful_count' => $article->getNotHelpfulCount(),
            'published_at' => $article->getPublishedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function category(ArticleCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
            'parent_id' => $category->getParent()?->getId(),
            'position' => $category->getPosition(),
        ];
    }
}
