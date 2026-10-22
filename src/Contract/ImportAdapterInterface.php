<?php

declare(strict_types=1);

namespace Escalated\Symfony\Contract;

use Escalated\Symfony\Import\ExtractResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A source the admin Import wizard can import from, as escalated-laravel's
 * Contracts\ImportAdapter. The bundle ships none: a host or a plugin bundle
 * provides them (a Zendesk or Freshdesk adapter, say), and any autoconfigured
 * service implementing this interface is registered.
 *
 * Records are returned already normalized (`source_id`, `title`,
 * `requester_source_id`, `tag_source_ids`, `ticket_source_id`, ...); see
 * {@see \Escalated\Symfony\Import\ImportService}.
 */
#[AutoconfigureTag('escalated.import_adapter')]
interface ImportAdapterInterface
{
    /** Unique slug, e.g. "zendesk". */
    public function name(): string;

    /** Human-readable name, e.g. "Zendesk". */
    public function displayName(): string;

    /**
     * Fields required for authentication.
     *
     * @return list<array{name: string, label: string, type?: string, help?: string}>
     */
    public function credentialFields(): array;

    /**
     * Validate credentials by making a test API call. May throw: the message
     * is shown to the admin.
     *
     * @param array<string, string> $credentials
     */
    public function testConnection(array $credentials): bool;

    /**
     * Importable entity types, in dependency order, e.g.
     * ['agents', 'tags', 'contacts', 'tickets', 'replies'].
     *
     * @return list<string>
     */
    public function entityTypes(): array;

    /**
     * Default field mappings for an entity type: source field => Escalated field.
     *
     * @return array<string, string>
     */
    public function defaultFieldMappings(string $entityType): array;

    /**
     * Source fields for an entity type, as the Mapping screen lists them.
     *
     * @param array<string, string> $credentials
     *
     * @return list<array{name: string, label?: string, escalated_options?: list<string>}>
     */
    public function availableSourceFields(string $entityType, array $credentials): array;

    /**
     * One batch of records. A null cursor in the result means the type is exhausted.
     *
     * @param array<string, string> $credentials
     */
    public function extract(string $entityType, array $credentials, ?string $cursor): ExtractResult;
}
