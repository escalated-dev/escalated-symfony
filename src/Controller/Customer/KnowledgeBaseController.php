<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Customer;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Entity\Article;
use Escalated\Symfony\Entity\ArticleCategory;
use Escalated\Symfony\Rendering\TicketPresenter;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\KnowledgeBaseService;
use Escalated\Symfony\Service\KnowledgeBaseSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The reader's knowledge base: Escalated/Customer/KnowledgeBase/Index and
 * Article, as escalated-laravel's Customer\KnowledgeBaseController. Only
 * published articles are shown. A disabled knowledge base answers 404, and
 * a non-public one needs a signed-in user.
 */
#[Route('/kb', name: 'escalated.customer.kb.')]
class KnowledgeBaseController extends UiController
{
    private const PER_PAGE = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly KnowledgeBaseService $kb,
        private readonly KnowledgeBaseSettings $settings,
        private readonly TicketPresenter $presenter,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertReadable();

        $filters = array_filter([
            'search' => $request->query->get('search'),
            'category' => $request->query->get('category'),
        ], static fn (mixed $v): bool => \is_string($v) && '' !== $v);

        $qb = $this->published()->leftJoin('a.category', 'c')->addSelect('c')
            ->orderBy('a.publishedAt', 'DESC')->addOrderBy('a.id', 'DESC');
        if (isset($filters['search'])) {
            $qb->andWhere('LOWER(a.title) LIKE :search OR LOWER(a.body) LIKE :search')
                ->setParameter('search', '%'.addcslashes(mb_strtolower($filters['search']), '%_').'%');
        }
        if (isset($filters['category'])) {
            $qb->andWhere('c.id = :category')->setParameter('category', (int) $filters['category']);
        }

        $counts = [];
        $rows = $this->published()->select('IDENTITY(a.category) AS id', 'COUNT(a.id) AS n')
            ->andWhere('a.category IS NOT NULL')->groupBy('a.category')->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        return $this->renderer->render('Escalated/Customer/KnowledgeBase/Index', [
            'categories' => array_map(static fn (ArticleCategory $c): array => [
                'id' => $c->getId(),
                'name' => $c->getName(),
                'slug' => $c->getSlug(),
                'description' => $c->getDescription(),
                'articles_count' => $counts[$c->getId()] ?? 0,
            ], $this->kb->rootCategoriesOrdered()),
            'articles' => $this->presenter->paginate(
                $qb,
                $request->query->getInt('page', 1),
                self::PER_PAGE,
                $this->generateUrl('escalated.customer.kb.index'),
                fn (Article $a): array => $this->summary($a),
                $filters,
            ),
            'filters' => $filters,
            'feedbackEnabled' => $this->settings->isFeedbackEnabled(),
        ]);
    }

    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(string $slug): Response
    {
        $this->assertReadable();
        $article = $this->article($slug);
        $this->kb->recordView($article);

        return $this->renderer->render('Escalated/Customer/KnowledgeBase/Article', [
            'article' => $this->summary($article) + [
                'body' => $article->getBody(),
                'helpful_count' => $article->getHelpfulCount(),
                'not_helpful_count' => $article->getNotHelpfulCount(),
            ],
            'related' => array_map(static fn (Article $a): array => [
                'id' => $a->getId(),
                'title' => $a->getTitle(),
                'slug' => $a->getSlug(),
            ], $this->kb->relatedArticles($article)),
            'feedbackEnabled' => $this->settings->isFeedbackEnabled(),
        ]);
    }

    #[Route('/{slug}/feedback', name: 'feedback', methods: ['POST'])]
    public function feedback(string $slug, Request $request): Response
    {
        $this->assertReadable();
        if (!$this->settings->isFeedbackEnabled()) {
            throw $this->createNotFoundException();
        }
        $article = $this->article($slug);

        $helpful = filter_var($this->payload($request)['helpful'] ?? null, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
        if (null === $helpful) {
            return $this->invalid($request, ['helpful' => 'The helpful field must be true or false.'], 'escalated.customer.kb.show', ['slug' => $slug]);
        }
        $this->kb->recordFeedback($article, $helpful);
        $this->addFlash('success', 'Thank you for your feedback!');

        return $this->back($request, 'escalated.customer.kb.show', ['slug' => $slug]);
    }

    private function assertReadable(): void
    {
        if (!$this->settings->isEnabled()) {
            throw $this->createNotFoundException('Knowledge base is disabled.');
        }
        if (!$this->settings->isPublicAccess() && !$this->getUser() instanceof UserInterface) {
            throw $this->createAccessDeniedException('Authentication required to access the knowledge base.');
        }
    }

    private function article(string $slug): Article
    {
        $article = $this->kb->findPublishedBySlug($slug);
        if (null === $article) {
            throw $this->createNotFoundException('Article not found.');
        }

        return $article;
    }

    private function published(): \Doctrine\ORM\QueryBuilder
    {
        return $this->em->getRepository(Article::class)->createQueryBuilder('a')
            ->where('a.status = :published')
            ->setParameter('published', Article::STATUS_PUBLISHED);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Article $article): array
    {
        $category = $article->getCategory();
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $article->getBody())));

        return [
            'id' => $article->getId(),
            'title' => $article->getTitle(),
            'slug' => $article->getSlug(),
            'excerpt' => mb_strlen($text) > 200 ? rtrim(mb_substr($text, 0, 200)).'...' : $text,
            'category' => null === $category ? null : ['id' => $category->getId(), 'name' => $category->getName()],
            'view_count' => $article->getViewCount(),
            'published_at' => TicketPresenter::iso($article->getPublishedAt()),
        ];
    }
}
