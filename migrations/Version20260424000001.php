<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260424000001 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_contacts + nullable ticket.contact_id FK';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $contacts = $schema->createTable('escalated_contacts');
        $contacts->addColumn('id', 'integer', ['autoincrement' => true]);
        $contacts->addColumn('email', 'string', ['length' => 320]);
        $contacts->addColumn('name', 'string', ['length' => 255, 'notnull' => false]);
        $contacts->addColumn('user_id', 'integer', ['notnull' => false]);
        $contacts->addColumn('metadata', 'json');
        $contacts->addColumn('created_at', 'datetime_immutable');
        $contacts->addColumn('updated_at', 'datetime_immutable');
        $contacts->setPrimaryKey(['id']);
        $contacts->addIndex(['user_id'], 'idx_contact_user');
        $contacts->addUniqueIndex(['email'], 'UNIQ_contact_email');

        $tickets = $schema->getTable('escalated_tickets');
        $tickets->addColumn('contact_id', 'integer', ['notnull' => false]);
        $tickets->addIndex(['contact_id'], 'idx_ticket_contact');
        $tickets->addForeignKeyConstraint('escalated_contacts', ['contact_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_ticket_contact');
    }

    public function down(Schema $schema): void
    {
        $tickets = $schema->getTable('escalated_tickets');
        $tickets->dropForeignKey('fk_ticket_contact');
        $tickets->dropIndex('idx_ticket_contact');
        $tickets->dropColumn('contact_id');

        $schema->dropTable('escalated_contacts');
    }
}
