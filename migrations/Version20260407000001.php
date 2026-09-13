<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260407000001 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Add snooze fields to escalated_tickets table';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $table = $schema->getTable('escalated_tickets');
        $table->addColumn('snoozed_until', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('snoozed_by', 'integer', ['notnull' => false]);
        $table->addColumn('status_before_snooze', 'string', ['length' => 32, 'notnull' => false]);
        $table->addIndex(['snoozed_until'], 'idx_ticket_snoozed_until');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('escalated_tickets');
        $table->dropIndex('idx_ticket_snoozed_until');
        $table->dropColumn('snoozed_until');
        $table->dropColumn('snoozed_by');
        $table->dropColumn('status_before_snooze');
    }
}
