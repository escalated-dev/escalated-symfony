<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Controller\TicketDeskController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin ticket desk: Escalated/Admin/Tickets/Index and Show, and the
 * writes those pages post (escalated-laravel's Admin\TicketController and
 * BulkActionController). The pages are the agent desk's plus `agents`,
 * `savedViews` and `is_snoozed`.
 */
#[Route('/admin/tickets', name: 'escalated.admin.tickets.')]
class TicketController extends TicketDeskController
{
    protected function gate(): string
    {
        return 'ESCALATED_ADMIN';
    }

    protected function routes(): string
    {
        return 'escalated.admin.tickets';
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        $me = $this->currentUserKey();

        return $this->renderer->render('Escalated/Admin/Tickets/Index', [
            ...$this->indexProps($request),
            'agents' => $this->users->agents(),
            'savedViews' => $this->presenter->savedViews(null === $me ? $this->savedViews->findShared() : $this->savedViews->findForUser($me)),
        ]);
    }

    #[Route('/bulk', name: 'bulk', methods: ['POST'], priority: 10)]
    public function bulk(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doBulk($request, true);
    }

    #[Route('/{reference}', name: 'show', methods: ['GET'])]
    public function show(string $reference): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        $ticket = $this->ticketOr404($reference);
        $props = $this->showProps($ticket);

        return $this->renderer->render('Escalated/Admin/Tickets/Show', [
            ...$props,
            'agents' => $this->users->agents(),
            'is_snoozed' => $props['ticket']['is_snoozed'],
        ]);
    }

    #[Route('/{reference}', name: 'update', methods: ['PATCH'])]
    public function update(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        $ticket = $this->ticketOr404($reference);
        $payload = $this->payload($request);
        $custom = $this->customFields?->validateTicketInput($payload, false) ?? ['errors' => [], 'values' => []];
        if ([] !== $custom['errors']) {
            return $this->invalid($request, $custom['errors'], ...$this->showRoute($ticket));
        }
        $this->tickets->update($ticket, array_intersect_key($payload, array_flip(['subject', 'description', 'priority', 'ticket_type'])));
        $this->customFields?->saveForTicket($ticket, $custom['values']);

        $this->addFlash('success', 'Ticket updated.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    #[Route('/{reference}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $reference): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        $ticket = $this->ticketOr404($reference);
        $ticket->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->addFlash('success', 'Ticket deleted.');

        return $this->redirectToRoute('escalated.admin.tickets.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{reference}/reply', name: 'reply', methods: ['POST'])]
    public function reply(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());
        $note = (bool) ($this->payload($request)['is_internal_note'] ?? false);

        return $this->doReply($reference, $request, $note);
    }

    #[Route('/{reference}/note', name: 'note', methods: ['POST'])]
    public function note(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doReply($reference, $request, true);
    }

    #[Route('/{reference}/assign', name: 'assign', methods: ['POST'])]
    public function assign(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doAssign($reference, $request);
    }

    #[Route('/{reference}/status', name: 'status', methods: ['POST'])]
    public function status(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doStatus($reference, $request);
    }

    #[Route('/{reference}/priority', name: 'priority', methods: ['POST'])]
    public function priority(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doPriority($reference, $request);
    }

    #[Route('/{reference}/tags', name: 'tags', methods: ['POST'])]
    public function tags(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doTags($reference, $request);
    }

    #[Route('/{reference}/department', name: 'department', methods: ['POST'])]
    public function department(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doDepartment($reference, $request);
    }

    #[Route('/{reference}/macro', name: 'macro', methods: ['POST'])]
    public function macro(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doMacro($reference, $request);
    }

    #[Route('/{reference}/follow', name: 'follow', methods: ['POST'])]
    public function follow(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doFollow($reference, $request);
    }

    #[Route('/{reference}/presence', name: 'presence', methods: ['POST'])]
    public function presence(string $reference): JsonResponse
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doPresence($reference);
    }

    #[Route('/{reference}/replies/{replyId}/pin', name: 'pin', requirements: ['replyId' => '\d+'], methods: ['POST'])]
    public function pin(string $reference, int $replyId, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doPin($reference, $replyId, $request);
    }

    #[Route('/{reference}/snooze', name: 'snooze', methods: ['POST'])]
    public function snooze(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doSnooze($reference, $request);
    }

    #[Route('/{reference}/unsnooze', name: 'unsnooze', methods: ['POST'])]
    public function unsnooze(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doUnsnooze($reference, $request);
    }
}
