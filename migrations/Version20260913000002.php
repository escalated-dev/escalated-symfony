<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Brings two live-chat tables in line with their entities:
 *
 *  - ChatRoutingRule::$department is a relation, but the table had no foreign
 *    key, so a rule could point at a deleted department. Rules that already do
 *    are detached before the key is added.
 *  - ChatSession::$ticket is one-to-one, but ticket_id carried only a plain
 *    index. It becomes unique. An install holding two sessions for one ticket
 *    stops here and has to resolve the duplicates first.
 */
final class Version20260913000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the chat routing rule department foreign key and make chat sessions unique per ticket';
    }

    public function up(Schema $schema): void
    {
        // Runs before the schema changes below (addSql precedes the diff).
        $this->addSql(
            'UPDATE escalated_chat_routing_rules SET department_id = NULL'
            .' WHERE department_id IS NOT NULL AND department_id NOT IN (SELECT id FROM escalated_departments)'
        );

        $rules = $schema->getTable('escalated_chat_routing_rules');
        $rules->addForeignKeyConstraint('escalated_departments', ['department_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_29030950AE80F5DF');

        $sessions = $schema->getTable('escalated_chat_sessions');
        foreach ($sessions->getIndexes() as $index) {
            if (!$index->isPrimary() && !$index->isUnique() && ['ticket_id'] === $index->getColumns()) {
                $sessions->dropIndex($index->getName());
            }
        }
        $sessions->addUniqueIndex(['ticket_id'], 'UNIQ_A13913B2700047D2');
    }

    public function down(Schema $schema): void
    {
        $sessions = $schema->getTable('escalated_chat_sessions');
        $sessions->dropIndex('UNIQ_A13913B2700047D2');
        $sessions->addIndex(['ticket_id'], 'IDX_A13913B2700047D2');

        $schema->getTable('escalated_chat_routing_rules')->dropForeignKey('FK_29030950AE80F5DF');
    }
}
