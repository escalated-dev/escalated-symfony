<?php

declare(strict_types=1);

namespace Escalated\Symfony\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Escalated\Symfony\Entity\DepartmentAgent;
use Escalated\Symfony\Entity\Ticket;

final class TicketVisibilityFilter extends SQLFilter
{
    private \Doctrine\ORM\EntityManagerInterface $manager;

    public function configure(\Doctrine\ORM\EntityManagerInterface $manager): void
    {
        $this->manager = $manager;
    }

    public const NAME = 'escalated_ticket_visibility';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return $this->constraint($targetEntity, $targetTableAlias);
    }

    /** Also used by the flush guard and raw SQL helpers. All values are SQLFilter-quoted parameters. */
    public function constraint(ClassMetadata $metadata, string $alias, array $seen = []): string
    {
        if (in_array($metadata->name, $seen, true) || !str_starts_with($metadata->name, 'Escalated\\Symfony\\Entity\\')) {
            return '';
        }
        $seen[] = $metadata->name;
        if (Ticket::class === $metadata->name) {
            $parts = [];
            if ($this->hasParameter('agent_id')) {
                $pivot = $this->table(DepartmentAgent::class);
                $identity = ExactIdentity::equals($this->getConnection()->getDatabasePlatform(), 'va.agent_id', $this->getParameter('agent_id'));
                $parts[] = "EXISTS (SELECT 1 FROM $pivot va WHERE va.department_id = $alias.department_id AND ($identity))";
            }
            if ($this->hasParameter('account_type')) {
                $parts[] = "$alias.account_type = ".$this->getParameter('account_type');
                $parts[] = "$alias.account_id = ".$this->getParameter('account_id');
                $parts[] = "LENGTH($alias.account_type) = LENGTH(".$this->getParameter('account_type').')';
                $parts[] = "LENGTH($alias.account_id) = LENGTH(".$this->getParameter('account_id').')';
            }
            if ($this->hasParameter('deny')) {
                $parts[] = '1 = 0';
            }

            return implode(' AND ', $parts);
        }
        if ($metadata->hasField('ticketId')) {
            return $this->parentConstraint(Ticket::class, "$alias.ticket_id", $seen);
        }
        if (\Escalated\Symfony\Entity\CustomFieldValue::class === $metadata->name) {
            $parent = $this->parentConstraint(Ticket::class, "$alias.entity_id", $seen);

            return "($alias.entity_type <> 'ticket' OR ($parent))";
        }
        $parts = [];
        $nonnull = [];
        foreach ($metadata->associationMappings as $mapping) {
            // ArrayAccess works for both ORM 2 arrays and ORM 3 mapping objects.
            if (!isset($mapping['joinColumns']) || [] === $mapping['joinColumns']) {
                continue;
            }
            $column = $mapping['joinColumns'][0]['name'];
            $part = $this->parentConstraint($mapping['targetEntity'], "$alias.$column", $seen);
            if ('' === $part) {
                continue;
            }
            $nullable = $mapping['joinColumns'][0]['nullable'] ?? true;
            $parts[] = $nullable ? "($alias.$column IS NULL OR $part)" : $part;
            $nonnull[] = "$alias.$column IS NOT NULL";
        }
        if ([] !== $parts) {
            $parts[] = '('.implode(' OR ', $nonnull).')';
        }

        return implode(' AND ', $parts);
    }

    private function parentConstraint(string $class, string $column, array $seen): string
    {
        $parent = $this->manager->getClassMetadata($class);
        $alias = 'vp'.count($seen);
        $constraint = $this->constraint($parent, $alias, $seen);

        return '' === $constraint ? '' : 'EXISTS (SELECT 1 FROM '.$this->table($class)." $alias WHERE $alias.id = $column AND ($constraint))";
    }

    private function table(string $class): string
    {
        return $this->manager->getConfiguration()->getQuoteStrategy()->getTableName($this->manager->getClassMetadata($class), $this->getConnection()->getDatabasePlatform());
    }
}
