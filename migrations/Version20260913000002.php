<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

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
final class Version20260913000002 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add the chat routing rule department foreign key and make chat sessions unique per ticket';
    }

    public function up(Schema $schema): void
    {
        // Runs before the schema changes below (addSql precedes the diff).
        $this->addSql(
            'UPDATE '.$this->table('chat_routing_rules').' SET department_id = NULL'
            .' WHERE department_id IS NOT NULL AND department_id NOT IN (SELECT id FROM '.$this->table('departments').')'
        );

        $rules = $schema->getTable($this->table('chat_routing_rules'));
        $rules->addForeignKeyConstraint($this->table('departments'), ['department_id'], ['id'], ['onDelete' => 'SET NULL'], $this->generatedName('fk', 'chat_routing_rules', ['department_id']));

        $sessions = $schema->getTable($this->table('chat_sessions'));
        foreach ($sessions->getIndexes() as $index) {
            if (!$index->isPrimary() && !$index->isUnique() && ['ticket_id'] === $index->getColumns()) {
                $sessions->dropIndex($index->getName());
            }
        }
        $sessions->addUniqueIndex(['ticket_id'], $this->generatedName('uniq', 'chat_sessions', ['ticket_id']));
    }

    public function down(Schema $schema): void
    {
        $sessions = $schema->getTable($this->table('chat_sessions'));
        $sessions->dropIndex($this->generatedName('uniq', 'chat_sessions', ['ticket_id']));
        $sessions->addIndex(['ticket_id'], $this->generatedName('idx', 'chat_sessions', ['ticket_id']));

        $schema->getTable($this->table('chat_routing_rules'))->dropForeignKey($this->generatedName('fk', 'chat_routing_rules', ['department_id']));
    }
}
