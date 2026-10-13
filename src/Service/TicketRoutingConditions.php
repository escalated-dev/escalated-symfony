<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Security\TicketAccess;

final class TicketRoutingConditions
{
    public static function constrain(\Doctrine\ORM\QueryBuilder $query, array $condition, int &$index): bool
    {
        $field = $condition['field'] ?? '';
        if (!in_array($field, ['ticket_type', 'account', 'account_type', 'account_id'], true)) {
            return false;
        }
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;
        if (!in_array($operator, ['equals', '=', '==', 'not_equals', '!='], true)) {
            $query->andWhere('1 = 0');

            return true;
        }
        $negate = in_array($operator, ['not_equals', '!='], true);
        // The final matcher enforces exact strings, including legacy type-column collations.
        if ($negate) {
            return true;
        }
        $parameter = 'routing'.$index++;
        if ('account' === $field) {
            $pair = TicketAccess::accountPair($value);
            if (null === $pair) {
                $query->andWhere('1 = 0');

                return true;
            }
            $query->andWhere('t.accountType IS NOT NULL AND t.accountId IS NOT NULL')
                ->andWhere(($negate ? 'NOT ' : '').'(t.accountType = :'.$parameter.'type AND t.accountId = :'.$parameter.'id)')
                ->setParameter($parameter.'type', $pair['type'])->setParameter($parameter.'id', $pair['id']);
        } else {
            if (!is_string($value) && !is_int($value)) {
                $query->andWhere('1 = 0');

                return true;
            }
            $property = match ($field) {
                'ticket_type' => 'ticketType', 'account_type' => 'accountType', 'account_id' => 'accountId',
            };
            $query->andWhere('t.'.$property.($negate ? ' <> :' : ' = :').$parameter)->setParameter($parameter, (string) $value);
        }

        return true;
    }

    public static function matches(Ticket $ticket, array $condition): ?bool
    {
        $field = $condition['field'] ?? '';
        if (!in_array($field, ['ticket_type', 'account', 'account_type', 'account_id'], true)) {
            return null;
        }
        $expected = $condition['value'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        if (!in_array($operator, ['equals', '=', '==', 'not_equals', '!='], true)) {
            return false;
        }
        if ('account' === $field) {
            $pair = TicketAccess::accountPair($expected);
            if (null === $pair || null === $ticket->getAccountType() || null === $ticket->getAccountId()) {
                return false;
            }
            $equal = $pair['type'] === $ticket->getAccountType() && $pair['id'] === $ticket->getAccountId();
        } else {
            if (!is_string($expected) && !is_int($expected)) {
                return false;
            }
            $actual = match ($field) {
                'ticket_type' => $ticket->getTicketType(), 'account_type' => $ticket->getAccountType(), 'account_id' => $ticket->getAccountId(),
            };
            if (null === $actual) {
                return false;
            }
            $equal = $actual === (string) $expected;
        }

        return in_array($operator, ['not_equals', '!='], true) ? !$equal : $equal;
    }
}
