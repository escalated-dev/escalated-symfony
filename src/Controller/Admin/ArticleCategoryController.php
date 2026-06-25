<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\ArticleCategory;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\KnowledgeBaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD over knowledge-base article categories. Mirrors the Laravel
 * Admin\ArticleCategoryController.
 */
#[Route('/admin/kb-categories', name: 'escalated.admin.kb_categories.')]
class ArticleCategoryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly KnowledgeBaseService $kb,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/KnowledgeBase/Categories/Index', [
            'categories' => array_map([$this, 'serialize'], $this->kb->orderedCategories()),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $category = new ArticleCategory();
        $this->applyPayload($category, $this->payload($request));

        $this->em->persist($category);
        $this->em->flush();

        $this->addFlash('success', 'Category created.');

        return $this->redirectToRoute('escalated.admin.kb_categories.index');
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $category = $this->em->getRepository(ArticleCategory::class)->find($id);
        if (null === $category) {
            throw $this->createNotFoundException('Category not found.');
        }

        $this->applyPayload($category, $this->payload($request));
        $this->em->flush();

        $this->addFlash('success', 'Category updated.');

        return $this->redirectToRoute('escalated.admin.kb_categories.index');
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $category = $this->em->getRepository(ArticleCategory::class)->find($id);
        if (null === $category) {
            throw $this->createNotFoundException('Category not found.');
        }

        $this->em->remove($category);
        $this->em->flush();

        $this->addFlash('success', 'Category deleted.');

        return $this->redirectToRoute('escalated.admin.kb_categories.index');
    }

    /** @param array<string, mixed> $payload */
    private function applyPayload(ArticleCategory $category, array $payload): void
    {
        $name = trim((string) ($payload['name'] ?? $category->getName()));
        $category->setName($name);

        $slug = trim((string) ($payload['slug'] ?? ''));
        $category->setSlug('' !== $slug ? $this->kb->slugify($slug) : $this->kb->slugify($name));

        if (array_key_exists('description', $payload)) {
            $category->setDescription(null === $payload['description'] ? null : (string) $payload['description']);
        }
        if (array_key_exists('position', $payload)) {
            $category->setPosition((int) $payload['position']);
        }
        if (array_key_exists('parent_id', $payload)) {
            $parentId = $payload['parent_id'];
            $category->setParent(
                null === $parentId || '' === $parentId
                    ? null
                    : $this->em->getRepository(ArticleCategory::class)->find((int) $parentId)
            );
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

    /** @return array<string, mixed> */
    private function serialize(ArticleCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
            'parent_id' => $category->getParent()?->getId(),
            'position' => $category->getPosition(),
            'description' => $category->getDescription(),
            'articles_count' => $this->kb->countArticles($category),
        ];
    }
}
