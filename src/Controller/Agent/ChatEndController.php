<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\ChatSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * End a live chat by its SESSION id (escalated-laravel's
 * `POST admin/chat/{session}/end`), under Laravel's route name
 * `escalated.admin.chat.end`, which the shared Agent/TicketShow page calls
 * with the ticket's `chat_session_id` and an empty JSON body. The older
 * `escalated.agent.chat.end` route, which takes a TICKET id, is unchanged.
 */
class ChatEndController extends AbstractController
{
    public function __construct(
        private readonly ChatSessionService $chatSessionService,
        private readonly EntityManagerInterface $em,
        private readonly UserKeyResolver $userKeys,
    ) {
    }

    #[Route('/agent/chat/{session}/end', name: 'escalated.admin.chat.end', requirements: ['session' => '\d+'], methods: ['POST'])]
    public function end(int $session): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $chat = $this->em->getRepository(ChatSession::class)->find($session);
        if (!$chat instanceof ChatSession) {
            return $this->json(['message' => 'Chat session not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($this->chatSessionService->isOver($chat)) {
            return $this->json(['message' => 'Chat is already ended.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->getUser();
        $this->chatSessionService->endSession($chat, null === $user ? null : $this->userKeys->resolve($user));

        return $this->json(['message' => 'Chat ended.']);
    }
}
