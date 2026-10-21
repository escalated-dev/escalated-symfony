<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\TicketMergeService;
use Escalated\Symfony\Service\TicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ticket merge for the shared TicketMergeDialog, like escalated-laravel's
 * TicketMergeController: search for a target, then merge into it.
 */
#[Route('/admin/tickets', name: 'escalated.admin.tickets.')]
class TicketMergeController extends AbstractController
{
    private const SEARCH_LIMIT = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketService $ticketService,
        private readonly TicketMergeService $mergeService,
        private readonly UserKeyResolver $userKeys,
    ) {
    }

    /**
     * Unmerged tickets whose reference or subject contains `q`. Registered
     * ahead of `/admin/tickets/{reference}`, which would read "merge-search"
     * as a reference.
     */
    #[Route('/merge-search', name: 'merge-search', methods: ['GET'], priority: 10)]
    public function search(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $term = trim((string) $request->query->get('q', ''));
        if ('' === $term) {
            return new JsonResponse(['message' => 'The q field is required.', 'errors' => ['q' => ['The q field is required.']]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // `%` and `_` in the term are literal, with an explicit escape
        // character: SQLite has no default one.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($term, 0, 191)).'%';

        /** @var list<Ticket> $tickets */
        $tickets = $this->em->getRepository(Ticket::class)->createQueryBuilder('t')
            ->where("t.reference LIKE :term ESCAPE '!' OR t.subject LIKE :term ESCAPE '!'")
            ->andWhere('t.mergedIntoId IS NULL')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('term', $like)
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(self::SEARCH_LIMIT)
            ->getQuery()
            ->getResult();

        return new JsonResponse(['tickets' => array_map(static fn (Ticket $t): array => [
            'id' => $t->getId(),
            'reference' => $t->getReference(),
            'subject' => $t->getSubject(),
            'status' => $t->getStatus(),
            'created_at' => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $tickets)]);
    }

    /**
     * Merge the ticket into the one named by `target_reference`.
     */
    #[Route('/{reference}/merge', name: 'merge', methods: ['POST'])]
    public function merge(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $targetReference = $this->payload($request)['target_reference'] ?? null;
        if (!\is_string($targetReference) || '' === trim($targetReference)) {
            return $this->back($ticket, 'error', 'Choose a ticket to merge into.');
        }

        $target = $this->ticketService->find(trim($targetReference));
        if (null === $target || null !== $target->getMergedIntoId()) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $user = $this->getUser();

        try {
            $this->mergeService->merge($ticket, $target, null === $user ? null : $this->userKeys->resolve($user));
        } catch (\InvalidArgumentException $e) {
            return $this->back($ticket, 'error', $e->getMessage());
        }

        return $this->back($ticket, 'success', sprintf('Ticket merged into %s.', $target->getReference()));
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        if (str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')) {
            $decoded = json_decode((string) $request->getContent(), true);

            return \is_array($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }

    private function back(Ticket $ticket, string $type, string $message): Response
    {
        $this->addFlash($type, $message);

        return $this->redirectToRoute('escalated.admin.tickets.show', ['reference' => $ticket->getReference()]);
    }
}
