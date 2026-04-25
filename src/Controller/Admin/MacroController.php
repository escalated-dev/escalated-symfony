<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD over Macro definitions.
 *
 * The agent-facing "apply this macro to this ticket" endpoint lives in
 * the Agent\MacroController. See escalated-developer-context/
 * domain-model/workflows-automations-macros.md.
 */
#[Route('/admin/macros', name: 'escalated.admin.macros.')]
class MacroController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $macros = $this->em->getRepository(Macro::class)->findBy([], ['name' => 'ASC']);

        return $this->renderer->render('Escalated/Admin/Macros/Index', [
            'macros' => array_map([$this, 'serialize'], $macros),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $macro = new Macro();
        $this->applyPayload($macro, $this->payload($request));
        $macro->setCreatedBy($this->currentUserId());

        $this->em->persist($macro);
        $this->em->flush();

        $this->addFlash('success', 'Macro created.');

        return $this->redirectToRoute('escalated.admin.macros.index');
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $macro = $this->em->getRepository(Macro::class)->find($id);
        if (null === $macro) {
            throw $this->createNotFoundException('Macro not found.');
        }

        $this->applyPayload($macro, $this->payload($request));
        $this->em->flush();

        $this->addFlash('success', 'Macro updated.');

        return $this->redirectToRoute('escalated.admin.macros.index');
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $macro = $this->em->getRepository(Macro::class)->find($id);
        if (null === $macro) {
            throw $this->createNotFoundException('Macro not found.');
        }

        $this->em->remove($macro);
        $this->em->flush();

        $this->addFlash('success', 'Macro deleted.');

        return $this->redirectToRoute('escalated.admin.macros.index');
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

    /** @param array<string, mixed> $payload */
    private function applyPayload(Macro $macro, array $payload): void
    {
        if (isset($payload['name'])) {
            $macro->setName((string) $payload['name']);
        }
        if (array_key_exists('description', $payload)) {
            $macro->setDescription($payload['description'] === null ? null : (string) $payload['description']);
        }
        if (isset($payload['actions']) && is_array($payload['actions'])) {
            $macro->setActions($payload['actions']);
        }
        if (array_key_exists('isShared', $payload)) {
            $macro->setIsShared((bool) $payload['isShared']);
        } elseif (array_key_exists('is_shared', $payload)) {
            // Accept snake_case for parity with NestJS / Laravel JSON.
            $macro->setIsShared((bool) $payload['is_shared']);
        }
    }

    private function currentUserId(): ?int
    {
        $user = $this->getUser();
        if ($user === null) {
            return null;
        }
        $id = $user->getUserIdentifier();
        return is_numeric($id) ? (int) $id : null;
    }

    /** @return array<string, mixed> */
    private function serialize(Macro $m): array
    {
        return [
            'id' => $m->getId(),
            'name' => $m->getName(),
            'description' => $m->getDescription(),
            'actions' => $m->getActions(),
            'is_shared' => $m->isShared(),
            'created_by' => $m->getCreatedBy(),
            'created_at' => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $m->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
