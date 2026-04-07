<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketActivity;
use Escalated\Symfony\Repository\TicketRepository;

class AssignmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketRepository $ticketRepository,
    ) {
    }

    /**
     * Assign a ticket to an agent.
     */
    public function assign(Ticket $ticket, int $agentId, ?int $causerId = null): Ticket
    {
        $ticket->setAssignedTo($agentId);
        $this->em->flush();

        $this->logActivity($ticket, TicketActivity::TYPE_ASSIGNED, $causerId, [
            'agent_id' => $agentId,
        ]);

        return $ticket;
    }

    /**
     * Unassign a ticket from its current agent.
     */
    public function unassign(Ticket $ticket, ?int $causerId = null): Ticket
    {
        $previousAgentId = $ticket->getAssignedTo();
        $ticket->setAssignedTo(null);
        $this->em->flush();

        $this->logActivity($ticket, TicketActivity::TYPE_UNASSIGNED, $causerId, [
            'previous_agent_id' => $previousAgentId,
        ]);

        return $ticket;
    }

    /**
     * Reassign a ticket to a different agent.
     */
    public function reassign(Ticket $ticket, int $agentId, ?int $causerId = null): Ticket
    {
        return $this->assign($ticket, $agentId, $causerId);
    }

    /**
     * Auto-assign a ticket to the agent with the fewest open tickets in the department.
     */
    public function autoAssign(Ticket $ticket): ?Ticket
    {
        $department = $ticket->getDepartment();
        if (null === $department) {
            return null;
        }

        // Auto-assignment requires a department-agent mapping to be configured.
        // In a full implementation this would query the department_agent pivot table.
        // For now, this returns null and should be extended by the host application.
        return null;
    }

    /**
     * Get workload stats for an agent.
     *
     * @return array{open: int, sla_breached: int}
     */
    public function getAgentWorkload(int $agentId): array
    {
        return [
            'open' => $this->ticketRepository->countOpenByAgent($agentId),
            'sla_breached' => count(
                array_filter(
                    $this->ticketRepository->findAssignedTo($agentId),
                    fn (Ticket $t) => $t->isOpen() && ($t->isSlaFirstResponseBreached() || $t->isSlaResolutionBreached())
                )
            ),
        ];
    }

    private function logActivity(Ticket $ticket, string $type, ?int $causerId = null, array $properties = []): void
    {
        $activity = new TicketActivity();
        $activity->setTicket($ticket);
        $activity->setType($type);
        $activity->setCauserId($causerId);
        $activity->setProperties(!empty($properties) ? $properties : null);

        $ticket->addActivity($activity);
        $this->em->persist($activity);
        $this->em->flush();
    }
}
