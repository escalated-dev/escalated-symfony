<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketLink;

/**
 * Manages directional links between tickets. Mirrors the Laravel
 * TicketLinkController logic: a ticket cannot link to itself, and two
 * tickets cannot be linked twice with the same type in either direction.
 */
class TicketLinkService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Whether a link type is one of the accepted values. Pure.
     */
    public function isValidLinkType(string $linkType): bool
    {
        return \in_array($linkType, TicketLink::TYPES, true);
    }

    /**
     * Whether the two tickets are already linked with the given type (in
     * either direction).
     */
    public function linkExists(Ticket $a, Ticket $b, string $linkType): bool
    {
        $repo = $this->em->getRepository(TicketLink::class);

        return null !== $repo->findOneBy([
            'parentTicket' => $a, 'childTicket' => $b, 'linkType' => $linkType,
        ]) || null !== $repo->findOneBy([
            'parentTicket' => $b, 'childTicket' => $a, 'linkType' => $linkType,
        ]);
    }

    /**
     * Link two tickets.
     *
     * @throws \InvalidArgumentException on invalid type, self-link, or duplicate
     */
    public function link(Ticket $source, Ticket $target, string $linkType): TicketLink
    {
        if (!$this->isValidLinkType($linkType)) {
            throw new \InvalidArgumentException('Invalid link type.');
        }
        if ($this->isSameTicket($source, $target)) {
            throw new \InvalidArgumentException('Cannot link a ticket to itself.');
        }
        if ($this->linkExists($source, $target, $linkType)) {
            throw new \InvalidArgumentException('These tickets are already linked.');
        }

        $link = (new TicketLink())
            ->setParentTicket($source)
            ->setChildTicket($target)
            ->setLinkType($linkType);

        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    public function unlink(TicketLink $link): void
    {
        $this->em->remove($link);
        $this->em->flush();
    }

    /**
     * All links touching a ticket, each tagged with its direction relative to
     * that ticket and the ticket on the other end.
     *
     * @return array<int, array{id: ?int, link_type: string, direction: string, ticket: ?Ticket}>
     */
    public function forTicket(Ticket $ticket): array
    {
        $repo = $this->em->getRepository(TicketLink::class);
        $links = [];

        foreach ($repo->findBy(['parentTicket' => $ticket]) as $link) {
            $links[] = [
                'id' => $link->getId(),
                'link_type' => $link->getLinkType(),
                'direction' => 'parent',
                'ticket' => $link->getChildTicket(),
            ];
        }

        foreach ($repo->findBy(['childTicket' => $ticket]) as $link) {
            $links[] = [
                'id' => $link->getId(),
                'link_type' => $link->getLinkType(),
                'direction' => 'child',
                'ticket' => $link->getParentTicket(),
            ];
        }

        return $links;
    }

    private function isSameTicket(Ticket $a, Ticket $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return null !== $a->getId() && $a->getId() === $b->getId();
    }
}
