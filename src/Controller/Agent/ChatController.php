<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Escalated\Symfony\Service\ChatSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/agent/chat', name: 'escalated.agent.chat.')]
class ChatController extends AbstractController
{
    public function __construct(
        private readonly ChatSessionService $chatSessionService,
    ) {
    }

    #[Route('/sessions', name: 'sessions', methods: ['GET'])]
    public function sessions(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $sessions = $this->chatSessionService->listActiveSessions();
        $data = [];

        foreach ($sessions as $session) {
            $ticket = $session->getTicket();
            $data[] = [
                'id' => $session->getId(),
                'status' => $session->getStatus(),
                'agent_id' => $session->getAgentId(),
                'ticket_reference' => $ticket->getReference(),
                'guest_name' => $ticket->getGuestName(),
                'created_at' => $session->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->json(['data' => $data]);
    }

    #[Route('/sessions/{id}/accept', name: 'accept', methods: ['POST'])]
    public function accept(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $session = $this->chatSessionService->findByTicket($id);
        if (null === $session) {
            return $this->json(['error' => 'Session not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$session->isWaiting()) {
            return $this->json(['error' => 'Session is not waiting for an agent.'], Response::HTTP_CONFLICT);
        }

        $agentId = (int) $this->getUser()->getUserIdentifier();
        $this->chatSessionService->assignAgent($session, $agentId);

        return $this->json(['data' => ['status' => 'accepted']]);
    }

    #[Route('/sessions/{id}/message', name: 'message', methods: ['POST'])]
    public function sendMessage(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $session = $this->chatSessionService->findByTicket($id);
        if (null === $session || !$session->isActive()) {
            return $this->json(['error' => 'Active session not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (empty($data['body'])) {
            return $this->json(
                ['error' => 'Message body is required.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $agentId = (int) $this->getUser()->getUserIdentifier();
        $this->chatSessionService->sendMessage(
            $session,
            $data['body'],
            $agentId,
            get_class($this->getUser())
        );

        return $this->json(['data' => ['status' => 'sent']], Response::HTTP_CREATED);
    }

    #[Route('/sessions/{id}/end', name: 'end', methods: ['POST'])]
    public function end(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $session = $this->chatSessionService->findByTicket($id);
        if (null === $session) {
            return $this->json(['error' => 'Session not found.'], Response::HTTP_NOT_FOUND);
        }

        $agentId = (int) $this->getUser()->getUserIdentifier();
        $this->chatSessionService->endSession($session, $agentId);

        return $this->json(['data' => ['status' => 'ended']]);
    }
}
