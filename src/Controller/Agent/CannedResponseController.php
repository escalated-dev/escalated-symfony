<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Escalated\Symfony\Entity\CannedResponse;
use Escalated\Symfony\Security\UserKeyResolver;
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
        private readonly UserKeyResolver $userKeys,
    ) {
    }

    #[Route('/canned-responses', name: 'canned_responses.index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        // No resolvable key: shared responses only.
        $responses = $this->service->listForAgent($this->currentUserId());

        return new JsonResponse(array_map([$this, 'serialize'], $responses));
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
        ];
    }
}
