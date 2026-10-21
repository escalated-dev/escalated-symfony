<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Entity\CannedResponse;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Rendering\UserDirectory;
use Escalated\Symfony\Security\UserKeyResolver;
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
#[Route('/admin/canned-responses', name: 'escalated.admin.')]
class CannedResponseController extends AbstractController
{
    public function __construct(
        private readonly CannedResponseService $service,
        private readonly UiRendererInterface $renderer,
        private readonly UserKeyResolver $userKeys,
        private readonly ?UserDirectory $users = null,
    ) {
    }

    #[Route('', name: 'canned-responses.index', methods: ['GET'])]
    #[Route('', name: 'canned_responses.index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/CannedResponses/Index', [
            'responses' => array_map([$this, 'serialize'], $this->service->listForAdmin()),
        ]);
    }

    /**
     * The frontend has no separate form page: Admin/CannedResponses/Index
     * creates and edits inline. Rendering a name it does not ship is not an
     * error in Inertia -- it resolves to nothing and the panel comes up blank
     * on a 200 -- so both of these routes take you to the list, where the form
     * actually is.
     */
    #[Route('/new', name: 'canned-responses.create', methods: ['GET'])]
    #[Route('/new', name: 'canned_responses.create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->redirectToRoute('escalated.admin.canned-responses.index');
    }

    #[Route('', name: 'canned-responses.store', methods: ['POST'])]
    #[Route('', name: 'canned_responses.store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $data = $this->normalize($this->payload($request));
        $data['createdBy'] = $this->currentUserId();

        $this->service->create($data);

        $this->addFlash('success', 'Canned response created.');

        return $this->redirectToRoute('escalated.admin.canned-responses.index');
    }

    #[Route('/{id}/edit', name: 'canned-responses.edit', methods: ['GET'])]
    #[Route('/{id}/edit', name: 'canned_responses.edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $response = $this->service->findById($id);
        if (null === $response) {
            throw $this->createNotFoundException('Canned response not found.');
        }

        return $this->redirectToRoute('escalated.admin.canned-responses.index');
    }

    #[Route('/{id}', name: 'canned-responses.update', methods: ['PATCH', 'PUT'])]
    #[Route('/{id}', name: 'canned_responses.update', methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $response = $this->service->findById($id);
        if (null === $response) {
            throw $this->createNotFoundException('Canned response not found.');
        }

        $this->service->update($response, $this->normalize($this->payload($request)));

        $this->addFlash('success', 'Canned response updated.');

        return $this->redirectToRoute('escalated.admin.canned-responses.index');
    }

    #[Route('/{id}', name: 'canned-responses.destroy', methods: ['DELETE'])]
    #[Route('/{id}', name: 'canned_responses.destroy', methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $response = $this->service->findById($id);
        if (null === $response) {
            throw $this->createNotFoundException('Canned response not found.');
        }

        $this->service->delete($response);

        $this->addFlash('success', 'Canned response deleted.');

        return $this->redirectToRoute('escalated.admin.canned-responses.index');
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

    /**
     * The signed-in user's host user key -- not getUserIdentifier(), which
     * is usually the email.
     */
    private function currentUserId(): int|string|null
    {
        $user = $this->getUser();

        return null === $user ? null : $this->userKeys->resolve($user);
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
            'creator' => $this->creator($c->getCreatedBy()),
            'created_at' => $c->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $c->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Who made it, as the page shows them: `{id, name}`.
     *
     * @return array{id: int|string, name: string}|null
     */
    private function creator(int|string|null $id): ?array
    {
        $user = $this->users?->find($id);

        return null === $user ? null : ['id' => $user['id'], 'name' => $user['name']];
    }
}
