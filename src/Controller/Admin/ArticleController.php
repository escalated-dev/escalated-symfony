<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Article;
use Escalated\Symfony\Entity\ArticleCategory;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\KnowledgeBaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD over knowledge-base articles. Mirrors the Laravel
 * Admin\ArticleController.
 */
#[Route('/admin/kb-articles', name: 'escalated.admin.kb_articles.')]
class ArticleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly KnowledgeBaseService $kb,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $search = $request->query->get('search');
        $status = $request->query->get('status');
        $categoryId = $request->query->get('category_id');

        $articles = $this->kb->searchArticles(
            null !== $search ? (string) $search : null,
            null !== $status ? (string) $status : null,
            null !== $categoryId && '' !== $categoryId ? (int) $categoryId : null,
        );

        return $this->renderer->render('Escalated/Admin/KnowledgeBase/Articles/Index', [
            'articles' => array_map([$this, 'serialize'], $articles),
            'categories' => $this->serializeCategoryOptions(),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'category_id' => $categoryId,
            ],
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $article = new Article();
        $this->applyPayload($article, $this->payload($request), true);

        $this->em->persist($article);
        $this->em->flush();

        $this->addFlash('success', 'Article created.');

        return $this->redirectToRoute('escalated.admin.kb_articles.index');
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $article = $this->em->getRepository(Article::class)->find($id);
        if (null === $article) {
            throw $this->createNotFoundException('Article not found.');
        }

        $this->applyPayload($article, $this->payload($request), false);
        $this->em->flush();

        $this->addFlash('success', 'Article updated.');

        return $this->redirectToRoute('escalated.admin.kb_articles.index');
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $article = $this->em->getRepository(Article::class)->find($id);
        if (null === $article) {
            throw $this->createNotFoundException('Article not found.');
        }

        $this->em->remove($article);
        $this->em->flush();

        $this->addFlash('success', 'Article deleted.');

        return $this->redirectToRoute('escalated.admin.kb_articles.index');
    }

    /** @param array<string, mixed> $payload */
    private function applyPayload(Article $article, array $payload, bool $isNew): void
    {
        $title = trim((string) ($payload['title'] ?? $article->getTitle()));
        $article->setTitle($title);

        $slug = trim((string) ($payload['slug'] ?? ''));
        $article->setSlug('' !== $slug ? $this->kb->slugify($slug) : $this->kb->slugify($title));

        if (array_key_exists('body', $payload)) {
            $article->setBody(null === $payload['body'] ? null : (string) $payload['body']);
        }

        $status = (string) ($payload['status'] ?? $article->getStatus());
        if (!\in_array($status, [Article::STATUS_DRAFT, Article::STATUS_PUBLISHED], true)) {
            $status = Article::STATUS_DRAFT;
        }
        $article->setStatus($status);

        if (array_key_exists('category_id', $payload)) {
            $categoryId = $payload['category_id'];
            $article->setCategory(
                null === $categoryId || '' === $categoryId
                    ? null
                    : $this->em->getRepository(ArticleCategory::class)->find((int) $categoryId)
            );
        }

        if ($isNew) {
            $user = $this->getUser();
            $article->setAuthorId(null !== $user ? $user->getUserIdentifier() : null);
        }

        if (Article::STATUS_PUBLISHED === $status && null === $article->getPublishedAt()) {
            $article->setPublishedAt(new \DateTimeImmutable());
        }
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        if ('' !== ($json = (string) $request->getContent())
            && str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')
        ) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function serializeCategoryOptions(): array
    {
        return array_map(
            static fn (ArticleCategory $c): array => ['id' => $c->getId(), 'name' => $c->getName()],
            $this->kb->orderedCategories(),
        );
    }

    /** @return array<string, mixed> */
    private function serialize(Article $article): array
    {
        return [
            'id' => $article->getId(),
            'title' => $article->getTitle(),
            'slug' => $article->getSlug(),
            'status' => $article->getStatus(),
            'category_id' => $article->getCategory()?->getId(),
            'category_name' => $article->getCategory()?->getName(),
            'view_count' => $article->getViewCount(),
            'helpful_count' => $article->getHelpfulCount(),
            'not_helpful_count' => $article->getNotHelpfulCount(),
            'published_at' => $article->getPublishedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
