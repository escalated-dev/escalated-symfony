<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/tags', name: 'escalated.admin.tags.')]
class TagController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly SluggerInterface $slugger,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $tags = $this->em->getRepository(Tag::class)->findAll();

        return $this->renderer->render('Escalated/Admin/Tags/Index', [
            'tags' => $tags,
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $tag = new Tag();
        $tag->setName($request->request->get('name', ''));
        $tag->setSlug(strtolower((string) $this->slugger->slug($tag->getName())));
        $tag->setColor($request->request->get('color'));

        $this->em->persist($tag);
        $this->em->flush();

        $this->addFlash('success', 'Tag created.');

        return $this->redirectToRoute('escalated.admin.tags.index');
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $tag = $this->em->getRepository(Tag::class)->find($id);
        if ($tag === null) {
            throw $this->createNotFoundException('Tag not found.');
        }

        if ($request->request->has('name')) {
            $tag->setName($request->request->get('name'));
            $tag->setSlug(strtolower((string) $this->slugger->slug($tag->getName())));
        }
        if ($request->request->has('color')) {
            $tag->setColor($request->request->get('color'));
        }

        $this->em->flush();

        $this->addFlash('success', 'Tag updated.');

        return $this->redirectToRoute('escalated.admin.tags.index');
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $tag = $this->em->getRepository(Tag::class)->find($id);
        if ($tag === null) {
            throw $this->createNotFoundException('Tag not found.');
        }

        $this->em->remove($tag);
        $this->em->flush();

        $this->addFlash('success', 'Tag deleted.');

        return $this->redirectToRoute('escalated.admin.tags.index');
    }
}
