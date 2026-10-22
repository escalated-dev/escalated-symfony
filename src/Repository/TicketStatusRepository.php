<?php

declare(strict_types=1);

namespace Escalated\Symfony\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketStatus;

/**
 * @extends ServiceEntityRepository<TicketStatus>
 *
 * @method TicketStatus|null find($id, $lockMode = null, $lockVersion = null)
 * @method TicketStatus|null findOneBy(array $criteria, array $orderBy = null)
 * @method TicketStatus[]    findAll()
 * @method TicketStatus[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TicketStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketStatus::class);
    }

    /**
     * Every row, by category then position (Laravel's order).
     *
     * @return list<TicketStatus>
     */
    public function ordered(): array
    {
        return array_values($this->findBy([], ['category' => 'ASC', 'position' => 'ASC', 'id' => 'ASC']));
    }

    /**
     * The custom (non-system) status with this slug. A system row's slug is
     * the built-in status itself, which callers handle as a built-in.
     */
    public function findCustomBySlug(string $slug): ?TicketStatus
    {
        return '' === $slug ? null : $this->findOneBy(['slug' => $slug, 'isSystem' => false]);
    }

    /**
     * Writes a status-change input straight onto the ticket, without the
     * transition check, timestamps or events of TicketService::changeStatus().
     * For the paths that already wrote the built-in status directly
     * (workflow, automation and macro actions): a custom slug applies that
     * custom status, anything else is written as before.
     */
    public static function applyStatus(EntityManagerInterface $em, Ticket $ticket, string $value): void
    {
        $repository = \array_key_exists($value, Ticket::TRANSITIONS) ? null : $em->getRepository(TicketStatus::class);
        $custom = $repository instanceof self ? $repository->findCustomBySlug($value) : null;
        null === $custom ? $ticket->setStatus($value) : $ticket->applyCustomStatus($custom);
    }

    /**
     * Adds a system row for each built-in status that has none. Idempotent:
     * the migration seeds them, this covers schemas built without migrations.
     */
    public function ensureSystemStatuses(): void
    {
        $existing = array_map(
            'strval',
            $this->createQueryBuilder('s')->select('s.slug')->where('s.slug IN (:slugs)')
                ->setParameter('slugs', array_keys(TicketStatus::SYSTEM))->getQuery()->getSingleColumnResult(),
        );

        $em = $this->getEntityManager();
        $added = false;
        $position = 0;
        foreach (TicketStatus::SYSTEM as $slug => [$label, $color, $category]) {
            ++$position;
            if (\in_array($slug, $existing, true)) {
                continue;
            }
            $em->persist((new TicketStatus())->setSlug($slug)->setLabel($label)->setColor($color)
                ->setCategory($category)->setPosition($position)->setIsSystem(true));
            $added = true;
        }
        if ($added) {
            $em->flush();
        }
    }

    /**
     * A slug for the label: lower case, `_`-separated, and `_2`, `_3`... when
     * taken. Built-in status names count as taken whether or not their system
     * rows exist, so a custom slug never shadows a built-in one.
     */
    public function uniqueSlug(string $label): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($label))), '_');
        $base = '' === $base ? 'status' : mb_substr($base, 0, 180);

        $taken = array_map('strval', $this->createQueryBuilder('s')->select('s.slug')
            // The base holds only [a-z0-9_]; `_` matching any character just
            // widens the candidates, and the exact check below decides.
            ->where('s.slug LIKE :prefix')
            ->setParameter('prefix', $base.'%')
            ->getQuery()->getSingleColumnResult());
        $taken = [...$taken, ...array_keys(Ticket::TRANSITIONS)];

        $slug = $base;
        for ($n = 2; \in_array($slug, $taken, true); ++$n) {
            $slug = $base.'_'.$n;
        }

        return $slug;
    }
}
