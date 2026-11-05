<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Article;
use Escalated\Symfony\Entity\ArticleCategory;

/**
 * Knowledge-base queries and helpers shared by the admin and public KB
 * surfaces. Mirrors the query scopes on the Laravel Article /
 * ArticleCategory models (published, search, ordered). Distinct from
 * {@see KnowledgeBaseSettings}, which holds KB configuration.
 */
class KnowledgeBaseService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Build a URL-friendly slug from arbitrary text. Pure.
     */
    public function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

        return trim($text, '-');
    }

    /**
     * All categories ordered by position then name.
     *
     * @return ArticleCategory[]
     */
    public function orderedCategories(): array
    {
        return $this->em->getRepository(ArticleCategory::class)
            ->findBy([], ['position' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * Root (top-level) categories ordered by position then name.
     *
     * @return ArticleCategory[]
     */
    public function rootCategoriesOrdered(): array
    {
        return $this->em->getRepository(ArticleCategory::class)
            ->findBy(['parent' => null], ['position' => 'ASC', 'name' => 'ASC']);
    }

    public function countArticles(ArticleCategory $category): int
    {
        return $this->em->getRepository(Article::class)->count(['category' => $category]);
    }

    /**
     * Admin article listing with optional search / status / category filters.
     *
     * @return Article[]
     */
    public function searchArticles(?string $search = null, ?string $status = null, ?int $categoryId = null): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Article::class, 'a')
            ->orderBy('a.createdAt', 'DESC');

        if (null !== $search && '' !== $search) {
            // Case-insensitive on every database, `%` and `_` taken literally.
            $qb->andWhere("LOWER(a.title) LIKE :term ESCAPE '!' OR LOWER(a.body) LIKE :term ESCAPE '!'")
               ->setParameter('term', self::likePattern($search));
        }
        if (null !== $status && '' !== $status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }
        if (null !== $categoryId) {
            $qb->andWhere('IDENTITY(a.category) = :cat')->setParameter('cat', $categoryId);
        }

        /** @var Article[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Knowledge-base search for agents (ADR 2026-11-05-agent-panel-data,
     * section 5): published articles whose title or body contains the term,
     * title matches first, then the most recently updated.
     *
     * @return Article[]
     */
    public function searchForAgents(string $term, int $limit): array
    {
        $pattern = self::likePattern($term);

        /** @var Article[] $result */
        $result = $this->em->createQueryBuilder()
            ->select('a')
            ->addSelect("CASE WHEN LOWER(a.title) LIKE :term ESCAPE '!' THEN 0 ELSE 1 END AS HIDDEN titleRank")
            ->from(Article::class, 'a')
            ->where('a.status = :published')
            ->andWhere("LOWER(a.title) LIKE :term ESCAPE '!' OR LOWER(a.body) LIKE :term ESCAPE '!'")
            ->setParameter('published', Article::STATUS_PUBLISHED)
            ->setParameter('term', $pattern)
            ->orderBy('titleRank', 'ASC')
            ->addOrderBy('a.updatedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** `%term%`, lower-cased, with `%`, `_` and the escape character `!` escaped. */
    public static function likePattern(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
    }

    /**
     * Find a published article by slug (public KB lookup).
     */
    public function findPublishedBySlug(string $slug): ?Article
    {
        return $this->em->getRepository(Article::class)
            ->findOneBy(['slug' => $slug, 'status' => Article::STATUS_PUBLISHED]);
    }

    /**
     * Published articles in the same category as the given one (excluding it).
     *
     * @return Article[]
     */
    public function relatedArticles(Article $article, int $limit = 5): array
    {
        $category = $article->getCategory();
        if (null === $category) {
            return [];
        }

        /** @var Article[] $result */
        $result = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Article::class, 'a')
            ->where('a.status = :status')->setParameter('status', Article::STATUS_PUBLISHED)
            ->andWhere('IDENTITY(a.category) = :category')->setParameter('category', $category->getId())
            ->andWhere('a.id != :id')->setParameter('id', $article->getId())
            ->orderBy('a.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Persist the running view count for an article.
     */
    public function recordView(Article $article): void
    {
        $article->incrementViews();
        $this->em->flush();
    }

    /**
     * Persist a helpful / not-helpful vote for an article.
     */
    public function recordFeedback(Article $article, bool $helpful): void
    {
        if ($helpful) {
            $article->markHelpful();
        } else {
            $article->markNotHelpful();
        }
        $this->em->flush();
    }
}
