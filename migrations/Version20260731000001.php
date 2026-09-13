<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260731000001 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_webhooks and escalated_webhook_deliveries tables';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $webhooks = $schema->createTable('escalated_webhooks');
        $webhooks->addColumn('id', 'integer', ['autoincrement' => true]);
        $webhooks->addColumn('url', 'string', ['length' => 500]);
        $webhooks->addColumn('events', 'json');
        $webhooks->addColumn('secret', 'string', ['length' => 255, 'notnull' => false]);
        $webhooks->addColumn('active', 'boolean');
        $webhooks->addColumn('created_at', 'datetime_immutable');
        $webhooks->addColumn('updated_at', 'datetime_immutable');
        $webhooks->setPrimaryKey(['id']);

        $deliveries = $schema->createTable('escalated_webhook_deliveries');
        $deliveries->addColumn('id', 'integer', ['autoincrement' => true]);
        $deliveries->addColumn('webhook_id', 'integer');
        $deliveries->addColumn('event', 'string', ['length' => 255]);
        $deliveries->addColumn('payload', 'json', ['notnull' => false]);
        $deliveries->addColumn('response_code', 'smallint', ['notnull' => false]);
        $deliveries->addColumn('response_body', 'text', ['notnull' => false]);
        $deliveries->addColumn('attempts', 'smallint');
        $deliveries->addColumn('delivered_at', 'datetime_immutable', ['notnull' => false]);
        $deliveries->addColumn('created_at', 'datetime_immutable');
        $deliveries->addColumn('updated_at', 'datetime_immutable');
        $deliveries->setPrimaryKey(['id']);
        $deliveries->addIndex(['webhook_id', 'event'], 'idx_webhook_delivery_webhook_event');
        $deliveries->addForeignKeyConstraint('escalated_webhooks', ['webhook_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_webhook_delivery_webhook');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_webhook_deliveries');
        $schema->dropTable('escalated_webhooks');
    }
}
