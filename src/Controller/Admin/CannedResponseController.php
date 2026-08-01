<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Entity\CannedResponse;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\CannedResponseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD over the canned-response library.
 *
 * The agent-facing "list responses I can insert" endpoint lives in the
 * Agent\CannedResponseController. Mirrors the CannedResponse admin surface
 * in escalated-laravel.
 */
#[Route('/admin/canned-responses', name: 'escalated.admin.canned_responses.')]
class CannedResponseController extends AbstractController
{
    public function __construct(
        private readonly CannedResponseService $service,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/CannedResponses/Index', [
            'responses' => array_map([$this, 'serialize'], $this->service->listForAdmin()),
        ]);
    }

    #[Route('/new', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/CannedResponses/Form', [
            'response' => null,
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $data = $this->normalize($this->payload($request));
        $data['createdBy'] = $this->currentUserId();

        $this->service->create($data);

        $this->addFlash('success', 'Canned response created.');

        return $this->redirectToRoute('escalated.admin.canned_responses.index');
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $response = $this->service->findById($id);
        if (null === $response) {
            throw $this->createNotFoundException('Canned response not found.');
        }

        return $this->renderer->render('Escalated/Admin/CannedResponses/Form', [
            'response' => $this->serialize($response),
        ]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $response = $this->service->findById($id);
        if (null === $response) {
            throw $this->createNotFoundException('Canned response not found.');
        }

        $this->service->update($response, $this->normalize($this->payload($request)));

        $this->addFlash('success', 'Canned response updated.');

        return $this->redirectToRoute('escalated.admin.canned_responses.index');
    }

    #[Route('/{id}', name: 'destroy', methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $response = $this->service->findById($id);
        if (null === $response) {
            throw $this->createNotFoundException('Canned response not found.');
        }

        $this->service->delete($response);

        $this->addFlash('success', 'Canned response deleted.');

        return $this->redirectToRoute('escalated.admin.canned_responses.index');
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

    /**
     * Normalise an inbound payload into the service's data shape, accepting
     * both snake_case (Laravel / NestJS JSON) and camelCase for is_shared.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalize(array $payload): array
    {
        $data = [];

        if (isset($payload['title'])) {
            $data['title'] = (string) $payload['title'];
        }
        if (isset($payload['body'])) {
            $data['body'] = (string) $payload['body'];
        }
        if (array_key_exists('category', $payload)) {
            $data['category'] = null === $payload['category'] ? null : (string) $payload['category'];
        }
        if (array_key_exists('isShared', $payload)) {
            $data['isShared'] = (bool) $payload['isShared'];
        } elseif (array_key_exists('is_shared', $payload)) {
            $data['isShared'] = (bool) $payload['is_shared'];
        }

        return $data;
    }

    private function currentUserId(): ?int
    {
        $user = $this->getUser();
        if (null === $user) {
            return null;
        }
        $id = $user->getUserIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    /** @return array<string, mixed> */
    private function serialize(CannedResponse $c): array
    {
        return [
            'id' => $c->getId(),
            'title' => $c->getTitle(),
            'body' => $c->getBody(),
            'category' => $c->getCategory(),
            'is_shared' => $c->isShared(),
            'created_by' => $c->getCreatedBy(),
            'created_at' => $c->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $c->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
