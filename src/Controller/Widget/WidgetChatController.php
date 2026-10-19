<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Widget;

use Escalated\Symfony\Service\ChatAvailabilityService;
use Escalated\Symfony\Service\ChatSessionService;
use Escalated\Symfony\Widget\WidgetMetadata;
use Escalated\Symfony\Widget\WidgetOrigins;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/widget/api/chat', name: 'escalated.widget.chat.')]
class WidgetChatController extends AbstractController
{
    public function __construct(
        private readonly WidgetSettings $widgetSettings,
        private readonly ChatSessionService $chatSessionService,
        private readonly ChatAvailabilityService $availabilityService,
    ) {
    }

    #[Route('/availability', name: 'availability', methods: ['GET'])]
    public function availability(Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return $this->json(['error' => 'Widget is disabled.'], Response::HTTP_NOT_FOUND);
        }

        $response = $this->json(['data' => $this->availabilityService->getStatus()]);

        return $response;
    }

    #[Route('/start', name: 'start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return $this->json(['error' => 'Widget is disabled.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $data = \is_array($data) ? $data : [];

        if (empty($data['guest_name']) || empty($data['guest_email'])) {
            return $this->json(
                ['error' => 'Name and email are required.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!WidgetMetadata::isValid($data['metadata'] ?? null)) {
            return $this->json(
                ['error' => 'Metadata must be an object with at most 50 entries.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Only the fields a chat needs, never the raw body: the visitor's IP
        // and user agent come from the request, host parameters are sanitised.
        $origin = $request->attributes->get(WidgetOrigins::ATTRIBUTE);
        $metadata = WidgetMetadata::sanitize($data['metadata'] ?? null, \is_string($origin) ? $origin : null);
        $pageUrl = $metadata['page_url'] ?? (WidgetMetadata::isWebUrl($data['page_url'] ?? null) ? $data['page_url'] : null);

        try {
            $result = $this->chatSessionService->startSession([
                'subject' => self::text($data['subject'] ?? null),
                'message' => self::text($data['message'] ?? null),
                'guest_name' => self::text($data['guest_name']),
                'guest_email' => self::text($data['guest_email']),
                'page_url' => \is_string($pageUrl) ? $pageUrl : null,
                'metadata' => $metadata,
                'visitor_ip' => $request->getClientIp(),
                'visitor_user_agent' => $request->headers->get('User-Agent'),
            ]);
        } catch (\Exception $e) {
            return $this->json(
                ['error' => 'Failed to start chat session.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $ticket = $result['ticket'];
        $session = $result['session'];

        $response = $this->json([
            'data' => [
                'session_id' => $session->getId(),
                'ticket_reference' => $ticket->getReference(),
                'guest_token' => $ticket->getGuestToken(),
                'status' => $session->getStatus(),
            ],
        ], Response::HTTP_CREATED);

        return $response;
    }

    #[Route('/sessions/{reference}/messages', name: 'messages', methods: ['POST'])]
    public function sendMessage(string $reference, Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return $this->json(['error' => 'Widget is disabled.'], Response::HTTP_NOT_FOUND);
        }

        $guestToken = $request->headers->get('X-Guest-Token');
        if (null === $guestToken) {
            return $this->json(['error' => 'Guest token required.'], Response::HTTP_UNAUTHORIZED);
        }

        $session = $this->findSessionByReferenceAndToken($reference, $guestToken);
        if (null === $session) {
            return $this->json(['error' => 'Session not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (empty($data['body'])) {
            return $this->json(
                ['error' => 'Message body is required.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $this->chatSessionService->sendMessage($session, $data['body']);

        $response = $this->json(['data' => ['status' => 'sent']], Response::HTTP_CREATED);

        return $response;
    }

    #[Route('/sessions/{reference}/end', name: 'end', methods: ['POST'])]
    public function endSession(string $reference, Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return $this->json(['error' => 'Widget is disabled.'], Response::HTTP_NOT_FOUND);
        }

        $guestToken = $request->headers->get('X-Guest-Token');
        if (null === $guestToken) {
            return $this->json(['error' => 'Guest token required.'], Response::HTTP_UNAUTHORIZED);
        }

        $session = $this->findSessionByReferenceAndToken($reference, $guestToken);
        if (null === $session) {
            return $this->json(['error' => 'Session not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->chatSessionService->endSession($session);

        $response = $this->json(['data' => ['status' => 'ended']]);

        return $response;
    }

    private static function text(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }

    private function findSessionByReferenceAndToken(string $reference, string $token): ?\Escalated\Symfony\Entity\ChatSession
    {
        $em = $this->container->get('doctrine')->getManager();
        $ticket = $em->getRepository(\Escalated\Symfony\Entity\Ticket::class)
            ->findOneBy(['reference' => $reference, 'guestToken' => $token, 'channel' => 'chat']);

        if (null === $ticket) {
            return null;
        }

        return $this->chatSessionService->findByTicket($ticket->getId());
    }
}
