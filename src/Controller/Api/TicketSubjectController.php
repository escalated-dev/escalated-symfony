<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Api;

use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Service\TicketSubjectService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/tickets', name: 'escalated.api.tickets.')]
class TicketSubjectController extends AbstractController
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly TicketSubjectService $ticketSubjectService,
    ) {
    }

    #[Route('/{reference}/subjects', name: 'subjects.attach', methods: ['POST'])]
    public function attach(string $reference, Request $request): JsonResponse
    {
        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            return $this->json(['error' => 'Ticket not found.'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $type = $body['type'] ?? null;
        $id = $body['id'] ?? null;
        if (!\is_string($type) || '' === $type || (!\is_string($id) && !\is_int($id))) {
            return $this->json(['error' => 'Subject type and id are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->ticketSubjectService->assertApiTypeAllowed($type);
            $link = $this->ticketSubjectService->attach(
                $ticket,
                $type,
                $id,
                isset($body['role']) && \is_string($body['role']) ? $body['role'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'data' => [
                'link' => [
                    'id' => $link->getId(),
                    'type' => $link->getSubjectType(),
                    'subject_id' => $link->getSubjectId(),
                    'role' => $link->getRole(),
                    'position' => $link->getPosition(),
                ],
                'subjects' => $this->ticketSubjectService->serializeLinks([$link]),
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/{reference}/subjects/{linkId}', name: 'subjects.detach', requirements: ['linkId' => '\d+'], methods: ['DELETE'])]
    public function detach(string $reference, int $linkId): JsonResponse
    {
        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            return $this->json(['error' => 'Ticket not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->ticketSubjectService->detach($ticket, $linkId);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
