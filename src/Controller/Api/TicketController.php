<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Api;

use Escalated\Symfony\Service\TicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    ) {}

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
        if ($ticket === null) {
            return $this->json(['error' => 'Ticket not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'data' => json_decode($this->serializer->serialize($ticket, 'json', ['groups' => 'ticket:detail']), true),
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
        if ($ticket === null) {
            return $this->json(['error' => 'Ticket not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $ticket = $this->ticketService->update($ticket, $data);

        return $this->json([
            'data' => json_decode($this->serializer->serialize($ticket, 'json', ['groups' => 'ticket:detail']), true),
        ]);
    }

    #[Route('/{reference}/status', name: 'status', methods: ['POST'])]
    public function changeStatus(string $reference, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->find($reference);
        if ($ticket === null) {
            return $this->json(['error' => 'Ticket not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $newStatus = $data['status'] ?? null;

        if ($newStatus === null) {
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
