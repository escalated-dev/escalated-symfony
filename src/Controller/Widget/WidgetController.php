<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Widget;

use Escalated\Symfony\Service\GuestRateLimiter;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Widget\WidgetMetadata;
use Escalated\Symfony\Widget\WidgetOrigins;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/widget/api', name: 'escalated.widget.')]
class WidgetController extends AbstractController
{
    public function __construct(
        private readonly WidgetSettings $widgetSettings,
        private readonly TicketService $ticketService,
        private readonly GuestRateLimiter $guestRateLimiter,
    ) {
    }

    #[Route('/config', name: 'config', methods: ['GET'])]
    public function config(Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return $this->json(['error' => 'Widget is disabled.'], Response::HTTP_NOT_FOUND);
        }

        // CORS for other sites is WidgetCorsSubscriber's job: an origin that
        // is not allowed gets this answer without a grant, so the browser
        // withholds it from the page.
        return $this->json(['data' => $this->widgetSettings->toPublicConfig()]);
    }

    #[Route('/tickets', name: 'tickets.store', methods: ['POST'])]
    public function createTicket(Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return $this->json(['error' => 'Widget is disabled.'], Response::HTTP_NOT_FOUND);
        }

        if (null !== $limited = $this->throttle(GuestRateLimiter::TICKET, $request)) {
            return $limited;
        }

        if (!$this->widgetSettings->isAllowGuestTickets()) {
            return $this->json(['error' => 'Guest tickets are disabled.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $data = \is_array($data) ? $data : [];

        if (empty($data['subject']) || empty($data['guest_name']) || empty($data['guest_email'])) {
            return $this->json(
                ['error' => 'Subject, name, and email are required.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!WidgetMetadata::isValid($data['metadata'] ?? null)) {
            return $this->json(
                ['error' => 'Metadata must be an object with at most 50 entries.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Host parameters from the embedding page, sanitised; `source` stays
        // the server's.
        $origin = $request->attributes->get(WidgetOrigins::ATTRIBUTE);
        $metadata = WidgetMetadata::sanitize($data['metadata'] ?? null, \is_string($origin) ? $origin : null) ?? [];
        $metadata['source'] = 'widget';

        try {
            $ticket = $this->ticketService->create([
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'guest_name' => $data['guest_name'],
                'guest_email' => $data['guest_email'],
                'metadata' => $metadata,
            ]);
        } catch (\Exception $e) {
            return $this->json(
                ['error' => 'Failed to create ticket.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $response = $this->json([
            'data' => [
                'reference' => $ticket->getReference(),
                'guest_token' => $ticket->getGuestToken(),
            ],
        ], Response::HTTP_CREATED);

        return $response;
    }

    #[Route('/tickets/{reference}', name: 'tickets.show', methods: ['GET'])]
    public function showTicket(string $reference, Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return $this->json(['error' => 'Widget is disabled.'], Response::HTTP_NOT_FOUND);
        }

        $guestToken = $request->headers->get('X-Guest-Token');
        if (null === $guestToken) {
            return $this->json(['error' => 'Guest token required.'], Response::HTTP_UNAUTHORIZED);
        }

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket || $ticket->getGuestToken() !== $guestToken) {
            return $this->json(['error' => 'Ticket not found.'], Response::HTTP_NOT_FOUND);
        }

        $repliesArray = [];
        foreach ($ticket->getPublicReplies() as $r) {
            $replyAttachments = [];
            foreach ($r->getAttachments() as $a) {
                $replyAttachments[] = $a->toArray();
            }

            $repliesArray[] = [
                'body' => $r->getBody(),
                'is_agent' => null !== $r->getAuthorClass(),
                'created_at' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'attachments' => $replyAttachments,
            ];
        }

        $ticketAttachments = [];
        foreach ($ticket->getAttachments() as $a) {
            $ticketAttachments[] = $a->toArray();
        }

        $response = $this->json([
            'data' => [
                'reference' => $ticket->getReference(),
                'subject' => $ticket->getSubject(),
                'status' => $ticket->getStatus(),
                'created_at' => $ticket->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'replies' => $repliesArray,
                'attachments' => $ticketAttachments,
            ],
        ]);

        return $response;
    }

    #[Route('/tickets/{reference}/replies', name: 'tickets.reply', methods: ['POST'])]
    public function replyToTicket(string $reference, Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return $this->json(['error' => 'Widget is disabled.'], Response::HTTP_NOT_FOUND);
        }

        // Before the guest token check, so requests with a missing or wrong
        // token are counted too and tokens cannot be guessed at speed.
        if (null !== $limited = $this->throttle(GuestRateLimiter::REPLY, $request)) {
            return $limited;
        }

        $guestToken = $request->headers->get('X-Guest-Token');
        if (null === $guestToken) {
            return $this->json(['error' => 'Guest token required.'], Response::HTTP_UNAUTHORIZED);
        }

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket || $ticket->getGuestToken() !== $guestToken) {
            return $this->json(['error' => 'Ticket not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (empty($data['body'])) {
            return $this->json(
                ['error' => 'Reply body is required.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $reply = $this->ticketService->addReply($ticket, 0, $data['body']);

        $response = $this->json([
            'data' => [
                'body' => $reply->getBody(),
                'created_at' => $reply->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ], Response::HTTP_CREATED);

        return $response;
    }

    /**
     * 429 with Retry-After when the client IP is over the guest limit for this
     * scope. See GuestRateLimiter for configuration and the trusted-proxy
     * requirement.
     */
    private function throttle(string $scope, Request $request): ?JsonResponse
    {
        $retryAfter = $this->guestRateLimiter->attempt($scope, $request->getClientIp() ?? 'unknown');
        if (null === $retryAfter) {
            return null;
        }

        return $this->json(
            ['error' => 'Too many requests. Please try again later.'],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter],
        );
    }
}
