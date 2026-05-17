<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Dto\SkillWriteInput;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\SkillService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/skills', name: 'escalated.admin.skills.')]
class SkillController extends AbstractController
{
    public function __construct(
        private readonly SkillService $skillService,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Skills/Index', [
            'skills' => $this->skillService->listForAdmin(),
        ]);
    }

    #[Route('/new', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ctx = $this->skillService->getFormContext();

        return $this->renderer->render('Escalated/Admin/Skills/Form', array_merge(
            ['skill' => null],
            $ctx
        ));
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        try {
            $input = SkillWriteInput::fromPayload($this->payload($request));
            $this->skillService->create($input);
        } catch (UnprocessableEntityHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Skill created.');

        return $this->redirectToRoute('escalated.admin.skills.index');
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        try {
            $skill = $this->skillService->findForEdit($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Skill not found.');
        }

        $ctx = $this->skillService->getFormContext();

        return $this->renderer->render('Escalated/Admin/Skills/Form', array_merge(
            ['skill' => $skill],
            $ctx
        ));
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        try {
            $input = SkillWriteInput::fromPayload($this->payload($request));
            $this->skillService->update($id, $input);
        } catch (UnprocessableEntityHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Skill not found.');
        }

        $this->addFlash('success', 'Skill updated.');

        return $this->redirectToRoute('escalated.admin.skills.index');
    }

    #[Route('/{id}', name: 'destroy', methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        try {
            $this->skillService->delete($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Skill not found.');
        }

        $this->addFlash('success', 'Skill deleted.');

        return $this->redirectToRoute('escalated.admin.skills.index');
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        if ('' !== ($json = (string) $request->getContent())
            && str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')
        ) {
            $decoded = json_decode($json, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }
}
