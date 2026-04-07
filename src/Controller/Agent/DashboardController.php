<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Repository\TicketRepository;
use Escalated\Symfony\Service\AssignmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/agent', name: 'escalated.agent.')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly AssignmentService $assignmentService,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $user = $this->getUser();
        $agentId = (int) $user->getUserIdentifier();

        $myTickets = $this->ticketRepository->findAssignedTo($agentId);
        $unassigned = $this->ticketRepository->findUnassigned();
        $workload = $this->assignmentService->getAgentWorkload($agentId);

        return $this->renderer->render('Escalated/Agent/Dashboard', [
            'my_tickets' => $myTickets,
            'unassigned_tickets' => $unassigned,
            'workload' => $workload,
        ]);
    }
}
