<?php

declare(strict_types=1);

namespace Escalated\Symfony\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Adds the newsletter tables' foreign keys to the schema Doctrine derives from
 * the mapping.
 *
 * The newsletter entities keep related ids as plain integer columns (no ORM
 * relations), so the mapping alone describes no foreign keys. The database has
 * them -- the migrations create them, and deleting a newsletter, list or
 * contact relies on their cascades to remove deliveries and list members --
 * and without this listener doctrine:schema:validate reports them as drift and
 * doctrine:schema:update would drop them.
 *
 * Names and delete rules match Version20260522000001.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final class NewsletterForeignKeysListener
{
    /**
     * table => list of [local column, foreign table, onDelete|null, constraint name].
     */
    private const FOREIGN_KEYS = [
        'escalated_newsletters' => [
            ['target_list_id', 'escalated_newsletter_lists', null, 'fk_n_list'],
            ['template_id', 'escalated_newsletter_templates', 'SET NULL', 'fk_n_template'],
        ],
        'escalated_newsletter_list_members' => [
            ['list_id', 'escalated_newsletter_lists', 'CASCADE', 'fk_nlm_list'],
            ['contact_id', 'escalated_contacts', 'CASCADE', 'fk_nlm_contact'],
        ],
        'escalated_newsletter_deliveries' => [
            ['newsletter_id', 'escalated_newsletters', 'CASCADE', 'fk_nd_newsletter'],
            ['contact_id', 'escalated_contacts', 'CASCADE', 'fk_nd_contact'],
        ],
    ];

    public function __construct(private readonly string $tablePrefix = 'escalated_')
    {
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $table = $args->getClassTable();
        $foreignKeys = null;
        foreach (self::FOREIGN_KEYS as $name => $keys) {
            if ($this->prefixed($name) === $table->getName()) {
                $foreignKeys = $keys;
                break;
            }
        }
        if (null === $foreignKeys) {
            return;
        }

        foreach ($foreignKeys as [$column, $foreignTable, $onDelete, $name]) {
            $foreignTable = $this->prefixed($foreignTable);
            if ($table->hasForeignKey($name)) {
                continue;
            }

            $table->addForeignKeyConstraint(
                $foreignTable,
                [$column],
                ['id'],
                null === $onDelete ? [] : ['onDelete' => $onDelete],
                $name,
            );
        }
    }

    /**
     * The table's real name under the configured `table_prefix`.
     */
    private function prefixed(string $table): string
    {
        return $this->tablePrefix.substr($table, \strlen('escalated_'));
    }
}
