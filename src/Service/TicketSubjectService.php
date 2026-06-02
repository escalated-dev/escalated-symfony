<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Contract\TicketSubjectResolverInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketSubjectLink;

/**
 * Attach, detach, sync, and serialize ticket subjects (host entities a ticket is about).
 */
class TicketSubjectService
{
    /**
     * @param list<string> $allowedTypes
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly array $allowedTypes = [],
        private readonly ?TicketSubjectResolverInterface $resolver = null,
    ) {
    }

    /**
     * @return list<TicketSubjectLink>
     */
    public function list(Ticket $ticket): array
    {
        if (null === $ticket->getId()) {
            return [];
        }

        return $this->em->getRepository(TicketSubjectLink::class)->findBy(
            ['ticket' => $ticket],
            ['position' => 'ASC'],
        );
    }

    public function attach(
        Ticket $ticket,
        string $subjectType,
        string|int $subjectId,
        ?string $role = null,
        bool $enforceAllowlist = true,
    ): TicketSubjectLink {
        if ($enforceAllowlist) {
            $this->assertTypeAllowed($subjectType);
        }

        $id = (string) $subjectId;

        $existing = $this->em->getRepository(TicketSubjectLink::class)->findOneBy([
            'ticket' => $ticket,
            'subjectType' => $subjectType,
            'subjectId' => $id,
        ]);

        if (null !== $existing) {
            if (null !== $role) {
                $existing->setRole($role);
                $this->em->flush();
            }

            return $existing;
        }

        $maxPosition = (int) $this->em->createQueryBuilder()
            ->select('MAX(l.position)')
            ->from(TicketSubjectLink::class, 'l')
            ->where('l.ticket = :ticket')
            ->setParameter('ticket', $ticket)
            ->getQuery()
            ->getSingleScalarResult();

        $link = new TicketSubjectLink();
        $link->setTicket($ticket);
        $link->setSubjectType($subjectType);
        $link->setSubjectId($id);
        $link->setRole($role);
        $link->setPosition($maxPosition + 1);

        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    public function detach(Ticket $ticket, int $linkId): void
    {
        $link = $this->em->getRepository(TicketSubjectLink::class)->findOneBy([
            'id' => $linkId,
            'ticket' => $ticket,
        ]);

        if (null === $link) {
            throw new \InvalidArgumentException(sprintf('Ticket subject link #%d not found.', $linkId));
        }

        $this->em->remove($link);
        $this->em->flush();
    }

    public function detachByKey(Ticket $ticket, string $subjectType, string|int $subjectId): int
    {
        $link = $this->em->getRepository(TicketSubjectLink::class)->findOneBy([
            'ticket' => $ticket,
            'subjectType' => $subjectType,
            'subjectId' => (string) $subjectId,
        ]);

        if (null === $link) {
            return 0;
        }

        $this->em->remove($link);
        $this->em->flush();

        return 1;
    }

    /**
     * @param list<array{subjectType: string, subjectId: string|int, role?: string|null}> $items
     *
     * @return list<TicketSubjectLink>
     */
    public function sync(Ticket $ticket, array $items, bool $enforceAllowlist = true): array
    {
        foreach ($this->list($ticket) as $existing) {
            $this->em->remove($existing);
        }
        $this->em->flush();

        $links = [];
        $position = 0;
        foreach ($items as $item) {
            if ($enforceAllowlist) {
                $this->assertTypeAllowed($item['subjectType']);
            }

            $link = new TicketSubjectLink();
            $link->setTicket($ticket);
            $link->setSubjectType($item['subjectType']);
            $link->setSubjectId((string) $item['subjectId']);
            $link->setRole($item['role'] ?? null);
            $link->setPosition($position++);

            $this->em->persist($link);
            $links[] = $link;
        }

        $this->em->flush();

        return $links;
    }

    /**
     * @param list<TicketSubjectLink> $links
     *
     * @return list<array{type: string, id: string, role: string|null, title: string, subtitle: string|null, url: string|null, color: string|null, icon: string|null, missing: bool}>
     */
    public function serializeLinks(array $links): array
    {
        $result = [];

        foreach ($links as $link) {
            $resolved = $this->resolver?->resolve($link->getSubjectType(), $link->getSubjectId());
            $presents = null !== $resolved;

            $result[] = [
                'type' => $link->getSubjectType(),
                'id' => $link->getSubjectId(),
                'role' => $link->getRole(),
                'title' => $presents
                    ? $resolved->ticketSubjectTitle()
                    : $link->getSubjectType().'#'.$link->getSubjectId(),
                'subtitle' => $presents ? $resolved->ticketSubjectSubtitle() : null,
                'url' => $presents ? $resolved->ticketSubjectUrl() : null,
                'color' => $presents ? $resolved->ticketSubjectColor() : null,
                'icon' => $presents ? $resolved->ticketSubjectIcon() : null,
                'missing' => !$presents,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{type: string, id: string, role: string|null, title: string, subtitle: string|null, url: string|null, color: string|null, icon: string|null, missing: bool}>
     */
    public function serializeForTicket(Ticket $ticket): array
    {
        return $this->serializeLinks($this->list($ticket));
    }

    /**
     * @return list<string>
     */
    public function getAllowedTypes(): array
    {
        return $this->allowedTypes;
    }

    public function assertApiTypeAllowed(string $subjectType): void
    {
        if ([] === $this->allowedTypes || !\in_array($subjectType, $this->allowedTypes, true)) {
            throw new \InvalidArgumentException(sprintf('Subject type [%s] is not an allowed ticket subject.', $subjectType));
        }
    }

    private function assertTypeAllowed(string $subjectType): void
    {
        if ([] !== $this->allowedTypes && !\in_array($subjectType, $this->allowedTypes, true)) {
            throw new \InvalidArgumentException(sprintf('Subject type [%s] is not an allowed ticket subject.', $subjectType));
        }
    }
}
