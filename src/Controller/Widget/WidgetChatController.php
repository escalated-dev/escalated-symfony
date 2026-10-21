<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Widget;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\GuestVerification;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Security\GuestEmailVerification;
use Escalated\Symfony\Security\GuestVerificationException;
use Escalated\Symfony\Service\ChatAvailabilityService;
use Escalated\Symfony\Service\ChatSessionService;
use Escalated\Symfony\Service\GuestRateLimiter;
use Escalated\Symfony\Widget\WidgetMetadata;
use Escalated\Symfony\Widget\WidgetOrigins;
use Escalated\Symfony\Widget\WidgetRequest;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Live chat for the widget. Two families of session routes:
 *
 * - `/chat/{id}/...` is what the shared widget calls. `{id}` is the chat's
 *   guest token (a random UUID returned as `id` by `/chat/start`), so the id
 *   itself is the visitor's credential, as in escalated-laravel.
 * - `/chat/sessions/{reference}/...` with `X-Guest-Token` is the original
 *   API, kept for existing clients.
 */
#[Route('/widget/api/chat', name: 'escalated.widget.chat.')]
class WidgetChatController extends AbstractController
{
    private const TOKEN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly WidgetSettings $widgetSettings,
        private readonly ChatSessionService $chatSessionService,
        private readonly ChatAvailabilityService $availabilityService,
        private readonly EntityManagerInterface $em,
        private readonly GuestRateLimiter $guestRateLimiter,
        private readonly GuestEmailVerification $verification,
    ) {
    }

    #[Route('/availability', name: 'availability', methods: ['GET'])]
    public function availability(Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return WidgetRequest::error('Widget is disabled.', Response::HTTP_NOT_FOUND);
        }

        $status = $this->availabilityService->getStatus();

        return $this->json($status + ['data' => $status]);
    }

    #[Route('/start', name: 'start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return WidgetRequest::error('Widget is disabled.', Response::HTTP_NOT_FOUND);
        }

        // Starting a chat creates a ticket, so it shares the guest ticket
        // limit with POST /tickets, checked before validation.
        if (null !== $limited = $this->throttle($request, GuestRateLimiter::TICKET)) {
            return $limited;
        }

        $data = WidgetRequest::body($request);
        $name = WidgetRequest::guestName($data);
        $email = WidgetRequest::guestEmail($data);

        if (null === $name || null === $email) {
            return WidgetRequest::error('Name and email are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!WidgetMetadata::isValid($data['metadata'] ?? null)) {
            return WidgetRequest::error('Metadata must be an object with at most 50 entries.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $department = WidgetRequest::department($this->em, $data);
        if (false === $department) {
            return WidgetRequest::error('The selected department is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Only the fields a chat needs, never the raw body: the visitor's IP
        // and user agent come from the request, host parameters are sanitised.
        $origin = $request->attributes->get(WidgetOrigins::ATTRIBUTE);
        $metadata = WidgetMetadata::sanitize($data['metadata'] ?? null, \is_string($origin) ? $origin : null);
        $pageUrl = $metadata['page_url'] ?? (WidgetMetadata::isWebUrl($data['page_url'] ?? null) ? $data['page_url'] : null);

        if (null !== $missing = WidgetController::missingProof($data)) {
            return $missing;
        }

        // A `chat` proof for this email, consumed with the chat start.
        try {
            $result = $this->verification->consume(
                $data['verification_id'],
                $data['verification_code'],
                $email,
                GuestVerification::PURPOSE_CHAT,
                fn (): array => $this->chatSessionService->startSession([
                    'subject' => self::text($data['subject'] ?? null),
                    'message' => self::text($data['message'] ?? null),
                    'guest_name' => $name,
                    'guest_email' => $email,
                    'department' => $department,
                    'page_url' => \is_string($pageUrl) ? $pageUrl : null,
                    'metadata' => $metadata,
                    'visitor_ip' => $request->getClientIp(),
                    'visitor_user_agent' => $request->headers->get('User-Agent'),
                ]),
            );
        } catch (GuestVerificationException $e) {
            return WidgetController::invalid($e);
        } catch (\Exception $e) {
            return WidgetRequest::error('Failed to start chat session.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $ticket = $result['ticket'];
        $session = $result['session'];

        return $this->json([
            // The shared widget addresses the chat by `id`: the random guest
            // token, never the sequential session id.
            'id' => $ticket->getGuestToken(),
            'reference' => $ticket->getReference(),
            'status' => $session->getStatus(),
            'messages' => $this->messageList($session),
            'agent' => $this->agent($session),
            'data' => [
                'session_id' => $session->getId(),
                'ticket_reference' => $ticket->getReference(),
                'guest_token' => $ticket->getGuestToken(),
                'status' => $session->getStatus(),
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/messages', name: 'widget.messages', requirements: ['id' => self::TOKEN], methods: ['GET'])]
    public function messages(string $id): JsonResponse
    {
        if (null !== $denied = $this->disabled()) {
            return $denied;
        }

        $session = $this->sessionByToken($id);
        if (null === $session) {
            return WidgetRequest::error('Chat not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'messages' => $this->messageList($session),
            'agent' => $this->agent($session),
            // Agent typing is not tracked by this backend.
            'typing' => null,
            'ended' => $this->chatSessionService->isOver($session),
        ]);
    }

    #[Route('/{id}/messages', name: 'widget.messages.store', requirements: ['id' => self::TOKEN], methods: ['POST'])]
    public function storeMessage(string $id, Request $request): JsonResponse
    {
        if (null !== $denied = $this->disabled()) {
            return $denied;
        }

        if (null !== $limited = $this->throttle($request)) {
            return $limited;
        }

        $session = $this->sessionByToken($id);
        if (null === $session) {
            return WidgetRequest::error('Chat not found.', Response::HTTP_NOT_FOUND);
        }
        if ($this->chatSessionService->isOver($session)) {
            return WidgetRequest::error('Chat has ended.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $body = self::text(WidgetRequest::body($request)['body'] ?? null);
        if (null === $body || '' === trim($body)) {
            return WidgetRequest::error('Message body is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->chatSessionService->sendMessage($session, $body);

        return $this->json(['message' => 'Message sent.']);
    }

    #[Route('/{id}/typing', name: 'widget.typing', requirements: ['id' => self::TOKEN], methods: ['POST'])]
    public function typing(string $id): JsonResponse
    {
        if (null !== $denied = $this->disabled()) {
            return $denied;
        }

        $session = $this->sessionByToken($id);
        if (null === $session) {
            return WidgetRequest::error('Chat not found.', Response::HTTP_NOT_FOUND);
        }
        if ($this->chatSessionService->isOver($session)) {
            return WidgetRequest::error('Chat has ended.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->chatSessionService->customerTyping($session);

        return $this->json(['message' => 'Typing updated.']);
    }

    #[Route('/{id}/end', name: 'widget.end', requirements: ['id' => self::TOKEN], methods: ['POST'])]
    public function end(string $id): JsonResponse
    {
        if (null !== $denied = $this->disabled()) {
            return $denied;
        }

        $session = $this->sessionByToken($id);
        if (null === $session) {
            return WidgetRequest::error('Chat not found.', Response::HTTP_NOT_FOUND);
        }
        if ($this->chatSessionService->isOver($session)) {
            return WidgetRequest::error('Chat has already ended.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->chatSessionService->endSession($session);

        return $this->json(['message' => 'Chat ended.']);
    }

    #[Route('/{id}/rate', name: 'widget.rate', requirements: ['id' => self::TOKEN], methods: ['POST'])]
    public function rate(string $id, Request $request): JsonResponse
    {
        if (null !== $denied = $this->disabled()) {
            return $denied;
        }

        if (null !== $limited = $this->throttle($request)) {
            return $limited;
        }

        $session = $this->sessionByToken($id);
        if (null === $session) {
            return WidgetRequest::error('Chat not found.', Response::HTTP_NOT_FOUND);
        }

        $data = WidgetRequest::body($request);
        $rating = $data['rating'] ?? null;
        if (!\is_int($rating)) {
            return WidgetRequest::error('Rating must be an integer between 1 and 5.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->chatSessionService->rateSession($session, $rating, self::text($data['comment'] ?? null));
        } catch (\InvalidArgumentException $e) {
            return WidgetRequest::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['message' => 'Rating submitted.']);
    }

    #[Route('/sessions/{reference}/messages', name: 'messages', methods: ['POST'])]
    public function sendMessage(string $reference, Request $request): JsonResponse
    {
        if (null !== $denied = $this->disabled()) {
            return $denied;
        }

        $guestToken = WidgetRequest::guestToken($request);
        if (null === $guestToken) {
            return WidgetRequest::error('Guest token required.', Response::HTTP_UNAUTHORIZED);
        }

        $session = $this->findSessionByReferenceAndToken($reference, $guestToken);
        if (null === $session) {
            return WidgetRequest::error('Session not found.', Response::HTTP_NOT_FOUND);
        }

        $data = WidgetRequest::body($request);
        if (empty($data['body']) || !\is_string($data['body'])) {
            return WidgetRequest::error('Message body is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->chatSessionService->sendMessage($session, $data['body']);

        return $this->json(['data' => ['status' => 'sent']], Response::HTTP_CREATED);
    }

    #[Route('/sessions/{reference}/end', name: 'end', methods: ['POST'])]
    public function endSession(string $reference, Request $request): JsonResponse
    {
        if (null !== $denied = $this->disabled()) {
            return $denied;
        }

        $guestToken = WidgetRequest::guestToken($request);
        if (null === $guestToken) {
            return WidgetRequest::error('Guest token required.', Response::HTTP_UNAUTHORIZED);
        }

        $session = $this->findSessionByReferenceAndToken($reference, $guestToken);
        if (null === $session) {
            return WidgetRequest::error('Session not found.', Response::HTTP_NOT_FOUND);
        }

        $this->chatSessionService->endSession($session);

        return $this->json(['data' => ['status' => 'ended']]);
    }

    private function disabled(): ?JsonResponse
    {
        return $this->widgetSettings->isEnabled()
            ? null
            : WidgetRequest::error('Widget is disabled.', Response::HTTP_NOT_FOUND);
    }

    /**
     * Messages in the widget's shape, oldest first. The visitor's opening
     * message is stored as the ticket description, so it leads the list.
     *
     * @return list<array<string, mixed>>
     */
    private function messageList(ChatSession $session): array
    {
        $ticket = $session->getTicket();
        $guestName = $ticket->getGuestName() ?? 'You';
        $messages = [];

        $opening = $ticket->getDescription();
        if (null !== $opening && '' !== trim($opening)) {
            $messages[] = [
                'id' => 'opening',
                'body' => $opening,
                'is_agent' => false,
                'author' => ['name' => $guestName],
                'created_at' => $ticket->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        $replies = $ticket->getPublicReplies()->toArray();
        usort($replies, static fn ($a, $b): int => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);
        foreach (\array_slice($replies, -100) as $reply) {
            $isAgent = $reply->isByAgent();
            $messages[] = [
                'id' => $reply->getId(),
                'body' => $reply->getBody(),
                'is_agent' => $isAgent,
                'author' => ['name' => $isAgent ? 'Support' : $guestName],
                'created_at' => $reply->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $messages;
    }

    /** @return array{name: string}|null */
    private function agent(ChatSession $session): ?array
    {
        return null !== $session->getAgentId() ? ['name' => 'Support'] : null;
    }

    private function throttle(Request $request, string $scope = GuestRateLimiter::REPLY): ?JsonResponse
    {
        $retryAfter = $this->guestRateLimiter->attempt($scope, $request->getClientIp() ?? 'unknown');

        return null === $retryAfter ? null : WidgetRequest::error(
            'Too many requests. Please try again later.',
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter],
        );
    }

    private static function text(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }

    private function sessionByToken(string $token): ?ChatSession
    {
        $ticket = $this->em->getRepository(Ticket::class)
            ->findOneBy(['guestToken' => strtolower($token), 'channel' => Ticket::CHANNEL_CHAT]);

        return null === $ticket ? null : $this->chatSessionService->findByTicket((int) $ticket->getId());
    }

    private function findSessionByReferenceAndToken(string $reference, string $token): ?ChatSession
    {
        $ticket = $this->em->getRepository(Ticket::class)
            ->findOneBy(['reference' => $reference, 'guestToken' => $token, 'channel' => Ticket::CHANNEL_CHAT]);

        return null === $ticket ? null : $this->chatSessionService->findByTicket((int) $ticket->getId());
    }
}
