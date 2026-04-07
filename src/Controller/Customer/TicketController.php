<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Customer;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Repository\DepartmentRepository;
use Escalated\Symfony\Service\TicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/customer/tickets', name: 'escalated.customer.tickets.')]
class TicketController extends AbstractController
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly UiRendererInterface $renderer,
        private readonly DepartmentRepository $departmentRepository,
        private readonly bool $allowCustomerClose,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        $filters = $request->query->all();
        $filters['requester_id'] = $user->getUserIdentifier();

        $tickets = $this->ticketService->list($filters);

        return $this->renderer->render('Escalated/Customer/Index', [
            'tickets' => $tickets,
            'filters' => $request->query->all(),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->renderer->render('Escalated/Customer/Create', [
            'departments' => $this->departmentRepository->findActive(),
            'priorities' => [
                Ticket::PRIORITY_LOW,
                Ticket::PRIORITY_MEDIUM,
                Ticket::PRIORITY_HIGH,
                Ticket::PRIORITY_URGENT,
                Ticket::PRIORITY_CRITICAL,
            ],
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        $data = $request->request->all();
        $data['requester_id'] = (int) $user->getUserIdentifier();
        $data['requester_class'] = get_class($user);

        $ticket = $this->ticketService->create($data);

        $this->addFlash('success', 'Ticket created successfully.');

        return $this->redirectToRoute('escalated.customer.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }

    #[Route('/{reference}', name: 'show', methods: ['GET'])]
    public function show(string $reference): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $this->authorizeCustomer($ticket);

        return $this->renderer->render('Escalated/Customer/Show', [
            'ticket' => $ticket,
        ]);
    }

    #[Route('/{reference}/reply', name: 'reply', methods: ['POST'])]
    public function reply(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $this->authorizeCustomer($ticket);

        $this->ticketService->addReply(
            $ticket,
            (int) $this->getUser()->getUserIdentifier(),
            $request->request->get('body', ''),
            false,
            get_class($this->getUser()),
        );

        $this->addFlash('success', 'Reply sent.');

        return $this->redirectToRoute('escalated.customer.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }

    #[Route('/{reference}/close', name: 'close', methods: ['POST'])]
    public function close(string $reference): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->allowCustomerClose) {
            throw $this->createAccessDeniedException('Customers cannot close tickets.');
        }

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $this->authorizeCustomer($ticket);

        $this->ticketService->close($ticket, (int) $this->getUser()->getUserIdentifier());

        $this->addFlash('success', 'Ticket closed.');

        return $this->redirectToRoute('escalated.customer.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }

    #[Route('/{reference}/reopen', name: 'reopen', methods: ['POST'])]
    public function reopenTicket(string $reference): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $this->authorizeCustomer($ticket);

        $this->ticketService->reopen($ticket, (int) $this->getUser()->getUserIdentifier());

        $this->addFlash('success', 'Ticket reopened.');

        return $this->redirectToRoute('escalated.customer.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }

    private function authorizeCustomer(Ticket $ticket): void
    {
        $user = $this->getUser();
        $userId = (int) $user->getUserIdentifier();
        $userClass = get_class($user);

        if ($ticket->getRequesterId() !== $userId || $ticket->getRequesterClass() !== $userClass) {
            throw $this->createAccessDeniedException('You do not own this ticket.');
        }
    }
}
