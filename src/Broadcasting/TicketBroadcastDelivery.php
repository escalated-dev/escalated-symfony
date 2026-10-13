<?php

declare(strict_types=1);

namespace Escalated\Symfony\Broadcasting;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Security\TicketAccess;

final class TicketBroadcastDelivery
{
    public function __construct(private readonly BroadcasterInterface $broadcaster, private readonly TicketAccess $access, private readonly EntityManagerInterface $em)
    {
    }

    public function broadcast(BroadcastableEvent $event): void
    {
        if (!$this->access->restricted()) {
            $this->broadcaster->broadcast($event);

            return;
        }
        // Legacy transports cannot reauthorize subscribers. Suppress them when restricted.
        if (!$this->broadcaster instanceof AuthorizedBroadcasterInterface) {
            return;
        }
        $payload = $event->getPayload();
        $criteria = isset($payload['ticket_id']) ? ['id' => $payload['ticket_id']] : ['reference' => $payload['ticket_reference'] ?? ''];
        $this->broadcaster->broadcastAuthorized($event, function (int|string $id) use ($criteria): bool {
            return $this->access->runAs(null, function () use ($criteria, $id): bool {
                $ticket = $this->em->getRepository(Ticket::class)->findOneBy($criteria);

                return null !== $ticket && $this->access->canReceive($ticket, $id);
            });
        });
    }
}
