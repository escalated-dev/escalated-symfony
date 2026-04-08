<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketActivity;

class TicketSplittingService
{
    public const ACTIVITY_TYPE_SPLIT_FROM = 'split_from';
    public const ACTIVITY_TYPE_SPLIT_TO = 'split_to';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketService $ticketService,
    ) {
    }

    /**
     * Split a ticket by creating a new ticket from a specific reply.
     *
     * The new ticket inherits the original ticket's metadata (priority, department,
     * tags, requester) and the reply body becomes the new ticket's description.
     * Both tickets are linked via metadata and activity logs.
     *
     * @param array{subject?: string} $overrides Optional overrides for the new ticket
     */
    public function splitTicket(Ticket $originalTicket, Reply $reply, int $causerId, array $overrides = []): Ticket
    {
        if ($reply->getTicket() !== $originalTicket) {
            throw new \InvalidArgumentException('The reply does not belong to the specified ticket.');
        }

        $subject = $overrides['subject'] ?? sprintf('Split from %s: %s', $originalTicket->getReference(), $originalTicket->getSubject());

        $newTicketData = [
            'subject' => $subject,
            'description' => $reply->getBody(),
            'priority' => $originalTicket->getPriority(),
            'ticket_type' => $originalTicket->getTicketType(),
        ];

        if (null !== $originalTicket->getRequesterId()) {
            $newTicketData['requester_id'] = $originalTicket->getRequesterId();
            $newTicketData['requester_class'] = $originalTicket->getRequesterClass();
        }

        if (null !== $originalTicket->getGuestName()) {
            $newTicketData['guest_name'] = $originalTicket->getGuestName();
            $newTicketData['guest_email'] = $originalTicket->getGuestEmail();
        }

        if (null !== $originalTicket->getDepartment()) {
            $newTicketData['department_id'] = $originalTicket->getDepartment()->getId();
        }

        $newTicket = $this->ticketService->create($newTicketData);

        // Copy tags
        foreach ($originalTicket->getTags() as $tag) {
            $newTicket->addTag($tag);
        }

        // Link tickets via metadata
        $originalMeta = $originalTicket->getMetadata() ?? [];
        $originalMeta['split_to'] = array_merge($originalMeta['split_to'] ?? [], [$newTicket->getReference()]);
        $originalTicket->setMetadata($originalMeta);

        $newMeta = $newTicket->getMetadata() ?? [];
        $newMeta['split_from'] = $originalTicket->getReference();
        $newMeta['split_reply_id'] = $reply->getId();
        $newTicket->setMetadata($newMeta);

        $this->em->flush();

        // Log activity on both tickets
        $this->logActivity($originalTicket, self::ACTIVITY_TYPE_SPLIT_TO, $causerId, [
            'new_ticket_reference' => $newTicket->getReference(),
            'reply_id' => $reply->getId(),
        ]);

        $this->logActivity($newTicket, self::ACTIVITY_TYPE_SPLIT_FROM, $causerId, [
            'original_ticket_reference' => $originalTicket->getReference(),
            'reply_id' => $reply->getId(),
        ]);

        return $newTicket;
    }

    /**
     * Get all tickets that were split from a given ticket.
     *
     * @return string[] Array of ticket references
     */
    public function getSplitChildren(Ticket $ticket): array
    {
        $meta = $ticket->getMetadata() ?? [];

        return $meta['split_to'] ?? [];
    }

    /**
     * Get the parent ticket reference if this ticket was split from another.
     */
    public function getSplitParent(Ticket $ticket): ?string
    {
        $meta = $ticket->getMetadata() ?? [];

        return $meta['split_from'] ?? null;
    }

    private function logActivity(Ticket $ticket, string $type, ?int $causerId, array $properties = []): void
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
