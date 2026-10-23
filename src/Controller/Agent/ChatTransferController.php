<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\ChatSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Live chat transfer (escalated-laravel ChatController::transfer), under
 * Laravel's route name `escalated.admin.chat.transfer`, which the shared
 * Agent/TicketShow page calls with the ticket's `chat_session_id`. Agents
 * can transfer, as with the rest of the chat console.
 */
class ChatTransferController extends AbstractController
{
    public function __construct(
        private readonly ChatSessionService $chatSessionService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Body `{agent_id, department_id}` (either or both); the shared
     * ChatActionBar's `{type: "agent"|"department", id}` is read too.
     */
    #[Route('/agent/chat/{session}/transfer', name: 'escalated.admin.chat.transfer', requirements: ['session' => '\d+'], methods: ['POST'])]
    public function transfer(int $session, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $chat = $this->em->getRepository(ChatSession::class)->find($session);
        if (!$chat instanceof ChatSession) {
            return $this->json(['message' => 'Chat session not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->getContent() ? json_decode($request->getContent(), true) : $request->request->all();
        if (!\is_array($data)) {
            $data = [];
        }
        $type = $data['type'] ?? null;
        if ('agent' === $type && self::blank($data['agent_id'] ?? null)) {
            $data['agent_id'] = $data['id'] ?? null;
        } elseif ('department' === $type && self::blank($data['department_id'] ?? null)) {
            $data['department_id'] = $data['id'] ?? null;
        }

        $agentId = null;
        if (!self::blank($data['agent_id'] ?? null)) {
            $agentId = UserKeyResolver::normalize($data['agent_id']);
            if (null === $agentId || \is_bool($data['agent_id']) || \is_array($data['agent_id'])) {
                return $this->unprocessable('The agent_id is invalid.');
            }
        }

        $department = null;
        if (!self::blank($data['department_id'] ?? null)) {
            $departmentId = filter_var($data['department_id'], \FILTER_VALIDATE_INT);
            if (false === $departmentId || \is_bool($data['department_id'])) {
                return $this->unprocessable('The department_id must be an integer.');
            }
            $department = $this->em->getRepository(Department::class)->find($departmentId);
            if (!$department instanceof Department) {
                return $this->unprocessable('The selected department_id is invalid.');
            }
        }

        if (null === $agentId && null === $department) {
            return $this->unprocessable('Provide an agent_id or department_id.');
        }

        try {
            $this->chatSessionService->transferChat($chat, $agentId, $department);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return $this->json([
            'message' => 'Chat transferred.',
            'session' => [
                'id' => $chat->getId(),
                'ticket_id' => $chat->getTicket()->getId(),
                'status' => $chat->getStatus(),
                'agent_id' => $chat->getAgentId(),
                'department_id' => $chat->getTicket()->getDepartment()?->getId(),
            ],
        ]);
    }

    private static function blank(mixed $value): bool
    {
        return null === $value || '' === $value || (\is_string($value) && '' === trim($value));
    }

    private function unprocessable(string $message): JsonResponse
    {
        return $this->json(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
