<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

/**
 * Seeds one system row per built-in ticket status, so the statuses admin page
 * lists them. Their own migration because SQL added in up() runs before that
 * migration's schema changes, so it could not insert into a table it creates.
 */
final class Version20261024000002 extends PrefixedMigration
{
    /**
     * slug => [label, colour, category]. A copy of TicketStatus::SYSTEM, so
     * this migration never changes when the entity does.
     */
    private const SYSTEM = [
        'open' => ['Open', '#3B82F6', 'open'],
        'in_progress' => ['In Progress', '#8B5CF6', 'open'],
        'waiting_on_customer' => ['Waiting on Customer', '#F59E0B', 'pending'],
        'waiting_on_agent' => ['Waiting on Agent', '#F97316', 'open'],
        'escalated' => ['Escalated', '#EF4444', 'open'],
        'resolved' => ['Resolved', '#10B981', 'solved'],
        'closed' => ['Closed', '#6B7280', 'solved'],
        'reopened' => ['Reopened', '#3B82F6', 'open'],
        'snoozed' => ['Snoozed', '#64748B', 'on_hold'],
        'live' => ['Live', '#06B6D4', 'open'],
    ];

    public function getDescription(): string
    {
        return 'Seed the built-in ticket statuses as system rows';
    }

    public function up(Schema $schema): void
    {
        $now = new \DateTimeImmutable();
        $position = 0;
        foreach (self::SYSTEM as $slug => [$label, $color, $category]) {
            $this->addSql(
                'INSERT INTO '.$this->table('ticket_statuses')
                .' (label, slug, category, color, description, position, is_default, is_system, created_at, updated_at)'
                .' VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)',
                [$label, $slug, $category, $color, ++$position, false, true, $now, $now],
                [Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::INTEGER, Types::BOOLEAN, Types::BOOLEAN, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM '.$this->table('ticket_statuses').' WHERE is_system = ?', [true], [Types::BOOLEAN]);
    }
}
