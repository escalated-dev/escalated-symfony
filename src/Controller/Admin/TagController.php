<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Escalated/Admin/Tags/Index, as escalated-laravel's Admin\TagController:
 * the page creates and edits tags inline.
 */
#[Route('/admin/tags', name: 'escalated.admin.tags.')]
class TagController extends UiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $counts = [];
        $rows = $this->em->createQueryBuilder()
            ->select('tag.id AS id', 'COUNT(t.id) AS n')
            ->from(Tag::class, 'tag')
            ->join('tag.tickets', 't')
            ->where('t.deletedAt IS NULL')
            ->groupBy('tag.id')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        $tags = array_map(static fn (Tag $tag): array => [
            'id' => $tag->getId(),
            'name' => $tag->getName(),
            'slug' => $tag->getSlug(),
            'color' => $tag->getColor(),
            'tickets_count' => $counts[$tag->getId()] ?? 0,
        ], $this->em->getRepository(Tag::class)->findBy([], ['name' => 'ASC']));

        return $this->renderer->render('Escalated/Admin/Tags/Index', [
            'tags' => $tags,
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $tag = new Tag();
        if ([] !== $errors = $this->apply($tag, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.tags.index');
        }

        $this->em->persist($tag);
        $this->em->flush();

        $this->addFlash('success', 'Tag created.');

        return $this->back($request, 'escalated.admin.tags.index');
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $tag = $this->find($id);
        if ([] !== $errors = $this->apply($tag, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.tags.index');
        }
        $this->em->flush();

        $this->addFlash('success', 'Tag updated.');

        return $this->back($request, 'escalated.admin.tags.index');
    }

    #[Route('/{id}', name: 'destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $this->em->remove($this->find($id));
        $this->em->flush();

        $this->addFlash('success', 'Tag deleted.');

        return $this->back($request, 'escalated.admin.tags.index');
    }

    private function find(int $id): Tag
    {
        $tag = $this->em->getRepository(Tag::class)->find($id);
        if (null === $tag) {
            throw $this->createNotFoundException('Tag not found.');
        }

        return $tag;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function apply(Tag $tag, array $payload): array
    {
        if (null === $tag->getId() || \array_key_exists('name', $payload)) {
            $name = \is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
            if ('' === $name || mb_strlen($name) > 255) {
                return ['name' => 'The name field is required.'];
            }
            $slug = strtolower((string) $this->slugger->slug($name));
            $taken = $this->em->getRepository(Tag::class)->findOneBy(['slug' => $slug]);
            if (null !== $taken && $taken->getId() !== $tag->getId()) {
                return ['name' => 'The name has already been taken.'];
            }
            $tag->setName($name)->setSlug($slug);
        }
        if (\array_key_exists('color', $payload)) {
            $tag->setColor(\is_string($payload['color']) && '' !== $payload['color'] ? $payload['color'] : null);
        }

        return [];
    }
}
