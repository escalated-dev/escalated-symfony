<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Escalated\Symfony\Controller\TicketDeskController;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Event\TicketCustomActionTriggeredEvent;
use Escalated\Symfony\Service\TicketActionRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The agent ticket desk: Escalated/Agent/TicketIndex and TicketShow, and the
 * writes those pages post (escalated-laravel's Agent\TicketController and
 * BulkActionController).
 */
#[Route('/agent/tickets', name: 'escalated.agent.tickets.')]
class TicketController extends TicketDeskController
{
    private TicketActionRegistry $ticketActions;
    private EventDispatcherInterface $dispatcher;

    #[Required]
    public function setCustomActions(TicketActionRegistry $ticketActions, EventDispatcherInterface $dispatcher): void
    {
        $this->ticketActions = $ticketActions;
        $this->dispatcher = $dispatcher;
    }

    protected function gate(): string
    {
        return 'ESCALATED_AGENT';
    }

    protected function routes(): string
    {
        return 'escalated.agent.tickets';
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
                'url' => $this->generateUrl('escalated.agent.tickets.custom-action', [
                    'reference' => $ticket->getReference(),
                    'actionKey' => $action['key'],
                ]),
                'method' => 'post',
            ]),
            $this->ticketActions->forTicket($ticket, $user),
        );
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->renderer->render('Escalated/Agent/TicketIndex', $this->indexProps($request));
    }

    #[Route('/bulk', name: 'bulk', methods: ['POST'], priority: 10)]
    public function bulk(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        return $this->doBulk($request, $this->isGranted('ESCALATED_ADMIN'));
    }

    #[Route('/{reference}', name: 'show', methods: ['GET'])]
    public function show(string $reference): Response
    {
        $this->denyAccessUnlessGranted($this->gate());

        $ticket = $this->ticketOr404($reference);

        return $this->renderer->render('Escalated/Agent/TicketShow', [
            ...$this->showProps($ticket),
            'customActions' => $this->customActionsForTicket($ticket, $this->getUser()),
        ]);
    }

    #[Route('/{reference}/actions/{actionKey}', name: 'custom-action', methods: ['POST'])]
    public function customAction(string $reference, string $actionKey, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $ticket = $this->ticketOr404($reference);

        $user = $this->getUser();
        $action = $this->ticketActions->find($actionKey);

        if (null === $action || !$action->isVisible($ticket, $user)) {
            throw $this->createNotFoundException('Custom action not found.');
        }
        if (!$action->isEnabled($ticket, $user)) {
            throw $this->createAccessDeniedException('Custom action is not enabled.');
        }

        $payload = $this->payload($request)['payload'] ?? [];
        $payload = \is_array($payload) ? $payload : [];

        $this->dispatcher->dispatch(new TicketCustomActionTriggeredEvent(
            $ticket,
            $action->getKey(),
            $user->getUserIdentifier(),
            $payload,
            $action->getMetadata($ticket, $user),
        ));

        $this->addFlash('success', 'Custom action dispatched.');

        return $this->redirectToRoute('escalated.agent.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
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
    public function changeStatus(string $reference, Request $request): Response
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
