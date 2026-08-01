<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Escalated\Symfony\Entity\CannedResponse;
use Escalated\Symfony\Service\CannedResponseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Agent endpoint for listing the canned responses an agent can insert:
 * shared responses + their own.
 *
 * Mirrors the escalated.api.canned-responses endpoint (Api\ResourceController
 * ::cannedResponses) in escalated-laravel. Admin management lives in
 * Admin\CannedResponseController.
 */
#[Route('/agent', name: 'escalated.agent.')]
class CannedResponseController extends AbstractController
{
    public function __construct(
        private readonly CannedResponseService $service,
    ) {
    }

    #[Route('/canned-responses', name: 'canned_responses.index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $agentId = $this->currentUserId() ?? 0;
        $responses = $this->service->listForAgent($agentId);

        return new JsonResponse(array_map([$this, 'serialize'], $responses));
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
        ];
    }
}
