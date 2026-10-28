<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

/**
 * Widget chat tokens become expiring grants, as in escalated-laravel: the chat
 * session keeps the token's SHA-256 (visitor_token_hash) and when it stops
 * working (visitor_token_expires_at). Chats already in progress have neither;
 * the next request with their token (still the chat ticket's guest_token)
 * moves it here with a full lifetime, so nobody is cut off by the upgrade.
 */
final class Version20261028000001 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add chat_sessions.visitor_token_hash and visitor_token_expires_at';
    }

    public function up(Schema $schema): void
    {
        $sessions = $schema->getTable($this->table('chat_sessions'));
        $sessions->addColumn('visitor_token_hash', 'string', ['length' => 64, 'notnull' => false]);
        $sessions->addColumn('visitor_token_expires_at', 'datetime_immutable', ['notnull' => false]);
        $sessions->addUniqueIndex(['visitor_token_hash'], 'uniq_chat_session_visitor_token');
    }

    public function down(Schema $schema): void
    {
        $sessions = $schema->getTable($this->table('chat_sessions'));
        $sessions->dropIndex('uniq_chat_session_visitor_token');
        $sessions->dropColumn('visitor_token_expires_at');
        $sessions->dropColumn('visitor_token_hash');
    }
}
