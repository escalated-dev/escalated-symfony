<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Api;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Event\TicketCustomActionTriggeredEvent;
use Escalated\Symfony\Service\SatisfactionRatingService;
use Escalated\Symfony\Service\TicketActionRegistry;
use Escalated\Symfony\Service\TicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/v1/tickets', name: 'escalated.api.tickets.')]
class TicketController extends AbstractController
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly SerializerInterface $serializer,
        private readonly TicketActionRegistry $ticketActions,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly SatisfactionRatingService $satisfactionRatings,
    ) {
    }

    /**
     * Serializes the visible custom actions for a ticket, adding url + method.
     *
     * @return array<int, array<string, mixed>>
     */
    private function customActionsForTicket(Ticket $ticket, mixed $user): array
    {
        return array_map(
            fn (array $action): array => array_merge($action, [
                'url' => $this->generateUrl('escalated.api.tickets.custom-action', [
                    'reference' => $ticket->getReference(),
                    'actionKey' => $action['key'],
                ]),
                'method' => 'post',
            ]),
            $this->ticketActions->forTicket($ticket, $user),
        );
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $tickets = $this->ticketService->list($request->query->all());

        return $this->json([
            'data' => json_decode($this->serializer->serialize($tickets, 'json', ['groups' => 'ticket:list']), true),
        ]);
    }

    #[Route('/{reference}', name: 'show', methods: ['GET'])]
    public function show(string $reference): JsonResponse
    {
        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            return $this->json(['error' => 'Ticket not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($this->serializer->serialize($ticket, 'json', ['groups' => 'ticket:detail']), true);
        $data['custom_actions'] = $this->customActionsForTicket($ticket, $this->getUser());

        return $this->json(['data' => $data]);
    }

    #[Route('/{reference}/actions/{actionKey}', name: 'custom-action', methods: ['POST'])]
    public function customAction(string $reference, string $actionKey, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            return $this->json(['error' => 'Ticket not found.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        $action = $this->ticketActions->find($actionKey);

        if (null === $action || !$action->isVisible($ticket, $user)) {
            return $this->json(['error' => 'Custom action not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$action->isEnabled($ticket, $user)) {
            return $this->json(['error' => 'Custom action is not enabled.'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true)['payload'] ?? [];

        $this->dispatcher->dispatch(new TicketCustomActionTriggeredEvent(
            $ticket,
            $action->getKey(),
            $user->getUserIdentifier(),
            is_array($payload) ? $payload : [],
            $action->getMetadata($ticket, $user),
        ));

        return $this->json([
            'message' => 'Custom action dispatched.',
            'action' => $action->getKey(),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        try {
            $ticket = $this->ticketService->create($data);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'data' => json_decode($this->serializer->serialize($ticket, 'json', ['groups' => 'ticket:detail']), true),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{reference}', name: 'update', methods: ['PATCH'])]
    public function update(string $reference, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            return $this->json(['error' => 'Ticket not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $ticket = $this->ticketService->update($ticket, $data);

        return $this->json([
            'data' => json_decode($this->serializer->serialize($ticket, 'json', ['groups' => 'ticket:detail']), true),
        ]);
    }

    #[Route('/{reference}/rating', name: 'rating', methods: ['POST'])]
    public function rate(string $reference, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            return $this->json(['error' => 'Ticket not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $user = $this->getUser();
        $comment = $data['comment'] ?? null;

        try {
            $rating = $this->satisfactionRatings->rate(
                $ticket,
                (int) ($data['rating'] ?? 0),
                null === $comment ? null : (string) $comment,
                null !== $user ? $user::class : null,
                null !== $user ? $user->getUserIdentifier() : null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'data' => [
                'id' => $rating->getId(),
                'rating' => $rating->getRating(),
                'comment' => $rating->getComment(),
                'created_at' => $rating->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/{reference}/status', name: 'status', methods: ['POST'])]
    public function changeStatus(string $reference, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            return $this->json(['error' => 'Ticket not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $newStatus = $data['status'] ?? null;

        if (null === $newStatus) {
            return $this->json(['error' => 'Status is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $ticket = $this->ticketService->changeStatus($ticket, $newStatus);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'data' => json_decode($this->serializer->serialize($ticket, 'json', ['groups' => 'ticket:detail']), true),
        ]);
    }
}
