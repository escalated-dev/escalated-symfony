<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Entity\Article;
use Escalated\Symfony\Entity\ArticleCategory;
use Escalated\Symfony\Rendering\TicketPresenter;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\KnowledgeBaseService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Escalated/Admin/KnowledgeBase/Articles/Index and Form, as escalated-laravel's
 * Admin\ArticleController (`escalated.admin.kb-articles.*`). The earlier
 * `escalated.admin.kb_articles.*` names stay for host links.
 */
#[Route('/admin/kb-articles', name: 'escalated.admin.')]
class ArticleController extends UiController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly KnowledgeBaseService $kb,
        private readonly TicketPresenter $presenter,
        private readonly UserKeyResolver $userKeys,
    ) {
    }

    #[Route('', name: 'kb-articles.index', methods: ['GET'])]
    #[Route('', name: 'kb_articles.index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $filters = array_filter([
            'search' => $request->query->get('search'),
            'status' => $request->query->get('status'),
            'category_id' => $request->query->get('category_id'),
        ], static fn (mixed $v): bool => \is_string($v) && '' !== $v);

        $qb = $this->em->getRepository(Article::class)->createQueryBuilder('a')
            ->leftJoin('a.category', 'c')->addSelect('c')
            ->orderBy('a.createdAt', 'DESC')->addOrderBy('a.id', 'DESC');
        if (isset($filters['search'])) {
            $qb->andWhere('LOWER(a.title) LIKE :search OR LOWER(a.body) LIKE :search')
                ->setParameter('search', '%'.addcslashes(mb_strtolower($filters['search']), '%_').'%');
        }
        if (isset($filters['status'])) {
            $qb->andWhere('a.status = :status')->setParameter('status', $filters['status']);
        }
        if (isset($filters['category_id'])) {
            $qb->andWhere('c.id = :category')->setParameter('category', (int) $filters['category_id']);
        }

        return $this->renderer->render('Escalated/Admin/KnowledgeBase/Articles/Index', [
            'articles' => $this->presenter->paginate(
                $qb,
                $request->query->getInt('page', 1),
                self::PER_PAGE,
                $this->generateUrl('escalated.admin.kb-articles.index'),
                fn (Article $a): array => $this->serialize($a),
                $filters,
            ),
            'categories' => $this->categoryOptions(),
            'filters' => $filters,
        ]);
    }

    #[Route('/create', name: 'kb-articles.create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/KnowledgeBase/Articles/Form', [
            'article' => null,
            'categories' => $this->categoryOptions(),
        ]);
    }

    #[Route('', name: 'kb-articles.store', methods: ['POST'])]
    #[Route('', name: 'kb_articles.store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $article = new Article();
        if ([] !== $errors = $this->apply($article, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.kb-articles.create');
        }
        $user = $this->getUser();
        $key = $user instanceof UserInterface ? $this->userKeys->resolve($user) : null;
        $article->setAuthorId(null === $key ? null : (string) $key);

        $this->em->persist($article);
        $this->em->flush();

        $this->addFlash('success', 'Article created.');

        return $this->redirectToRoute('escalated.admin.kb-articles.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/edit', name: 'kb-articles.edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $article = $this->find($id);

        return $this->renderer->render('Escalated/Admin/KnowledgeBase/Articles/Form', [
            'article' => $this->serialize($article) + ['body' => $article->getBody()],
            'categories' => $this->categoryOptions(),
        ]);
    }

    #[Route('/{id}', name: 'kb-articles.update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT'])]
    #[Route('/{id}', name: 'kb_articles.update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $article = $this->find($id);
        if ([] !== $errors = $this->apply($article, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.kb-articles.edit', ['id' => $id]);
        }
        $this->em->flush();

        $this->addFlash('success', 'Article updated.');

        return $this->redirectToRoute('escalated.admin.kb-articles.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'kb-articles.destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[Route('/{id}', name: 'kb_articles.delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $this->em->remove($this->find($id));
        $this->em->flush();

        $this->addFlash('success', 'Article deleted.');

        return $this->redirectToRoute('escalated.admin.kb-articles.index', [], Response::HTTP_SEE_OTHER);
    }

    private function find(int $id): Article
    {
        $article = $this->em->getRepository(Article::class)->find($id);
        if (null === $article) {
            throw $this->createNotFoundException('Article not found.');
        }

        return $article;
    }

    /**
     * As Laravel's article request: title required, status draft or
     * published, an existing category, a unique slug (from the title when
     * none is given).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function apply(Article $article, array $payload): array
    {
        $errors = [];

        $title = \is_string($payload['title'] ?? null) ? trim($payload['title']) : '';
        if ('' === $title || mb_strlen($title) > 255) {
            $errors['title'] = 'The title field is required.';
        }
        $status = $payload['status'] ?? null;
        if (!\in_array($status, [Article::STATUS_DRAFT, Article::STATUS_PUBLISHED], true)) {
            $errors['status'] = 'The selected status is invalid.';
        }
        $category = null;
        $categoryId = $payload['category_id'] ?? null;
        if (null !== $categoryId && '' !== $categoryId) {
            $category = is_numeric($categoryId) ? $this->em->getRepository(ArticleCategory::class)->find((int) $categoryId) : null;
            if (null === $category) {
                $errors['category_id'] = 'The selected category is invalid.';
            }
        }
        $given = \is_string($payload['slug'] ?? null) ? trim($payload['slug']) : '';
        $slug = $this->kb->slugify('' !== $given ? $given : $title);
        $taken = '' === $slug ? null : $this->em->getRepository(Article::class)->findOneBy(['slug' => $slug]);
        if (null !== $taken && $taken->getId() !== $article->getId()) {
            $errors['slug'] = 'The slug has already been taken.';
        }
        if ([] !== $errors) {
            return $errors;
        }

        $article->setTitle($title);
        $article->setSlug($slug);
        if (\array_key_exists('body', $payload)) {
            $article->setBody(\is_string($payload['body']) ? $payload['body'] : null);
        }
        $article->setStatus((string) $status);
        $article->setCategory($category);
        if (Article::STATUS_PUBLISHED === $status && null === $article->getPublishedAt()) {
            $article->setPublishedAt(new \DateTimeImmutable());
        }

        return [];
    }

    /**
     * @return list<array{id: int|null, name: string}>
     */
    private function categoryOptions(): array
    {
        return array_map(
            static fn (ArticleCategory $c): array => ['id' => $c->getId(), 'name' => $c->getName()],
            $this->kb->orderedCategories(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Article $article): array
    {
        $category = $article->getCategory();

        return [
            'id' => $article->getId(),
            'title' => $article->getTitle(),
            'slug' => $article->getSlug(),
            'status' => $article->getStatus(),
            'category_id' => $category?->getId(),
            'category' => null === $category ? null : ['id' => $category->getId(), 'name' => $category->getName()],
            'category_name' => $category?->getName(),
            'view_count' => $article->getViewCount(),
            'helpful_count' => $article->getHelpfulCount(),
            'not_helpful_count' => $article->getNotHelpfulCount(),
            'published_at' => TicketPresenter::iso($article->getPublishedAt()),
            'created_at' => TicketPresenter::iso($article->getCreatedAt()),
            'updated_at' => TicketPresenter::iso($article->getUpdatedAt()),
        ];
    }
}
