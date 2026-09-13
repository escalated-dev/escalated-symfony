<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260604000001 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Add next_attempt_at retry-backoff column to escalated_newsletter_deliveries';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $deliveries = $schema->getTable('escalated_newsletter_deliveries');
        $deliveries->addColumn('next_attempt_at', 'datetime', ['notnull' => false]);
        $deliveries->addIndex(['status', 'next_attempt_at'], 'idx_escalated_nl_deliveries_claim');
    }

    public function down(Schema $schema): void
    {
        $deliveries = $schema->getTable('escalated_newsletter_deliveries');
        $deliveries->dropIndex('idx_escalated_nl_deliveries_claim');
        $deliveries->dropColumn('next_attempt_at');
    }
}
