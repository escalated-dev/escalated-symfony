<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260413000001 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_attachments table for ticket and reply file attachments';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $attachments = $schema->createTable('escalated_attachments');
        $attachments->addColumn('id', 'integer', ['autoincrement' => true]);
        $attachments->addColumn('ticket_id', 'integer', ['notnull' => false]);
        $attachments->addColumn('reply_id', 'integer', ['notnull' => false]);
        $attachments->addColumn('original_filename', 'string', ['length' => 255]);
        $attachments->addColumn('stored_filename', 'string', ['length' => 255]);
        $attachments->addColumn('mime_type', 'string', ['length' => 127, 'notnull' => false]);
        $attachments->addColumn('size', 'integer');
        $attachments->addColumn('disk', 'string', ['length' => 32, 'default' => 'local']);
        $attachments->addColumn('path', 'string', ['length' => 512, 'default' => '']);
        $attachments->addColumn('url', 'string', ['length' => 1024, 'notnull' => false]);
        $attachments->addColumn('created_at', 'datetime_immutable');
        $attachments->setPrimaryKey(['id']);
        $attachments->addIndex(['ticket_id'], 'idx_attachment_ticket');
        $attachments->addIndex(['reply_id'], 'idx_attachment_reply');
        $attachments->addForeignKeyConstraint('escalated_tickets', ['ticket_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_attachment_ticket');
        $attachments->addForeignKeyConstraint('escalated_replies', ['reply_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_attachment_reply');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_attachments');
    }
}
