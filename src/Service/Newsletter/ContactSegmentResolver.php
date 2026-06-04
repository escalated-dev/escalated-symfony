<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service\Newsletter;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Newsletter\NewsletterList;
use Escalated\Symfony\Entity\Newsletter\NewsletterListMember;

class ContactSegmentResolver
{
    /**
     * Filter fields the dynamic-list builder may target, mapped to the Doctrine
     * entity property used in DQL. Anything not listed here is rejected, so the
     * `field` value from a saved filter can never be interpolated verbatim.
     */
    private const ALLOWED_FIELDS = [
        'id' => 'id',
        'email' => 'email',
        'name' => 'name',
        'user_id' => 'userId',
        'created_at' => 'createdAt',
        'updated_at' => 'updatedAt',
        'marketing_opt_out_at' => 'marketingOptOutAt',
    ];

    /** Operator allowlist → canonical DQL operator (anything else is rejected). */
    private const ALLOWED_OPS = [
        '=' => '=', '==' => '=',
        '!=' => '!=', '<>' => '!=',
        '<' => '<', '<=' => '<=',
        '>' => '>', '>=' => '>=',
        'like' => 'LIKE', 'LIKE' => 'LIKE',
    ];

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
            $op = self::ALLOWED_OPS[$rule['op'] ?? '='] ?? null;
            $value = $rule['value'] ?? null;
            // Skip rules with no field or a disallowed operator — never interpolate
            // an unvalidated operator into DQL.
            if (!$field || null === $op) {
                continue;
            }

            if (str_starts_with($field, 'metadata.')) {
                // Metadata JSON contains-check; SQL backend-specific. The key is part
                // of the JSON path string (not bindable), so restrict it to a safe
                // character set to block injection.
                $key = substr($field, strlen('metadata.'));
                if (!preg_match('/^[A-Za-z0-9_]+$/', $key)) {
                    continue;
                }
                $param = 'p'.($idx++);
                $qb->andWhere(sprintf("JSON_EXTRACT(c.metadata, '$.%s') %s :%s", $key, $op, $param))
                    ->setParameter($param, $value);
                continue;
            }

            // Map the requested field to a known Doctrine property; reject unknowns.
            $property = self::ALLOWED_FIELDS[$field] ?? null;
            if (null === $property) {
                continue;
            }
            $param = 'p'.($idx++);
            $qb->andWhere(sprintf('c.%s %s :%s', $property, $op, $param))
                ->setParameter($param, $value);
        }
    }
}
