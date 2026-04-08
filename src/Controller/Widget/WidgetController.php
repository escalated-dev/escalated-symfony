<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Widget;

use Escalated\Symfony\Service\TicketService;
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
    ) {
    }

    #[Route('/config', name: 'config', methods: ['GET'])]
    public function config(Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return $this->json(['error' => 'Widget is disabled.'], Response::HTTP_NOT_FOUND);
        }

        $origin = $request->headers->get('Origin', '');
        if ('' !== $origin && !$this->widgetSettings->isOriginAllowed($origin)) {
            return $this->json(['error' => 'Origin not allowed.'], Response::HTTP_FORBIDDEN);
        }

        $response = $this->json(['data' => $this->widgetSettings->toPublicConfig()]);
        if ('' !== $origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        return $response;
    }

    #[Route('/tickets', name: 'tickets.store', methods: ['POST'])]
    public function createTicket(Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return $this->json(['error' => 'Widget is disabled.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->widgetSettings->isAllowGuestTickets()) {
            return $this->json(['error' => 'Guest tickets are disabled.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['subject']) || empty($data['guest_name']) || empty($data['guest_email'])) {
            return $this->json(
                ['error' => 'Subject, name, and email are required.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $ticket = $this->ticketService->create([
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'guest_name' => $data['guest_name'],
                'guest_email' => $data['guest_email'],
                'metadata' => ['source' => 'widget'],
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

        $origin = $request->headers->get('Origin', '');
        if ('' !== $origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

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
            $repliesArray[] = [
                'body' => $r->getBody(),
                'is_agent' => null !== $r->getAuthorClass(),
                'created_at' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        $response = $this->json([
            'data' => [
                'reference' => $ticket->getReference(),
                'subject' => $ticket->getSubject(),
                'status' => $ticket->getStatus(),
                'created_at' => $ticket->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'replies' => $repliesArray,
            ],
        ]);

        $origin = $request->headers->get('Origin', '');
        if ('' !== $origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        return $response;
    }

    #[Route('/tickets/{reference}/replies', name: 'tickets.reply', methods: ['POST'])]
    public function replyToTicket(string $reference, Request $request): JsonResponse
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

        $origin = $request->headers->get('Origin', '');
        if ('' !== $origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        return $response;
    }
}
