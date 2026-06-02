<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service\Newsletter;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Newsletter\NewsletterList;
use Escalated\Symfony\Entity\Newsletter\NewsletterListMember;

class ContactSegmentResolver
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array<int> */
    public function resolve(NewsletterList $list): array
    {
        if ('static' === $list->getKind()) {
            return $this->staticIds($list);
        }

        return $this->applyFilter($list->getFilterJson() ?? ['rules' => []])
            ->select('c.id')->getQuery()->getSingleColumnResult();
    }

    /** @return array<int> */
    public function resolveSendable(NewsletterList $list): array
    {
        $qb = $this->em->createQueryBuilder()
            ->from(Contact::class, 'c')
            ->select('c.id')
            ->where('c.marketingOptOutAt IS NULL');

        if ('static' === $list->getKind()) {
            $ids = $this->staticIds($list);
            if (!$ids) {
                return [];
            }
            $qb->andWhere('c.id IN (:ids)')->setParameter('ids', $ids);
        } else {
            $this->appendFilter($qb, $list->getFilterJson() ?? ['rules' => []]);
        }

        return array_map('intval', $qb->getQuery()->getSingleColumnResult());
    }

    public function countMatches(array $filter): int
    {
        $qb = $this->em->createQueryBuilder()->from(Contact::class, 'c')->select('COUNT(c.id)');
        $this->appendFilter($qb, $filter);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return array<int> */
    private function staticIds(NewsletterList $list): array
    {
        return array_map(
            'intval',
            $this->em->createQueryBuilder()
                ->from(NewsletterListMember::class, 'm')
                ->select('m.contactId')
                ->where('m.listId = :lid')
                ->setParameter('lid', $list->getId())
                ->getQuery()
                ->getSingleColumnResult(),
        );
    }

    private function applyFilter(array $filter): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->from(Contact::class, 'c')->select('c.id');
        $this->appendFilter($qb, $filter);

        return $qb;
    }

    private function appendFilter(\Doctrine\ORM\QueryBuilder $qb, array $filter): void
    {
        $idx = 0;
        foreach ($filter['rules'] ?? [] as $rule) {
            $field = $rule['field'] ?? null;
            $op = $rule['op'] ?? '=';
            $value = $rule['value'] ?? null;
            if (!$field) {
                continue;
            }
            $param = 'p'.($idx++);
            if (str_starts_with($field, 'metadata.')) {
                // Metadata JSON contains-check; SQL backend-specific. For v1, push it down as raw.
                $key = substr($field, strlen('metadata.'));
                $qb->andWhere("JSON_EXTRACT(c.metadata, '$.{$key}') = :{$param}")->setParameter($param, $value);
                continue;
            }
            $qb->andWhere("c.{$field} {$op} :{$param}")->setParameter($param, $value);
        }
    }
}
