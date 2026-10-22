<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures;

use Escalated\Symfony\Contract\ImportAdapterInterface;
use Escalated\Symfony\Import\ExtractResult;

/**
 * An import source for tests. It stands in for a plugin-provided adapter
 * (Zendesk, Freshdesk, ...): the bundle never talks to a third-party API
 * itself, it only drives the ImportAdapterInterface services it is given.
 *
 * The records are fixed: two contacts (one of them already a host user), one
 * tag, three tickets served two to a page, and two replies. `$onExtract` lets
 * a test act between batches, for example to pause the job.
 */
final class FakeImportAdapter implements ImportAdapterInterface
{
    public const TOKEN = 'secret-token';
    public const CREDENTIALS = ['subdomain' => 'acme', 'api_token' => self::TOKEN];

    public const CREDENTIAL_FIELDS = [
        ['name' => 'subdomain', 'label' => 'Subdomain', 'type' => 'text', 'help' => 'acme'],
        ['name' => 'api_token', 'label' => 'API token', 'type' => 'password', 'help' => ''],
    ];

    public const TICKET_SOURCE_FIELDS = [
        ['name' => 'subject', 'label' => 'Subject', 'escalated_options' => ['title', 'description']],
        ['name' => 'state', 'label' => 'State', 'escalated_options' => ['status']],
    ];

    private const RECORDS = [
        'contacts' => [
            ['source_id' => 'c1', 'email' => 'ada@customer.test', 'name' => 'Ada Customer'],
            ['source_id' => 'c2', 'email' => 'bob@customer.test', 'name' => 'Bob Customer'],
        ],
        'tags' => [['source_id' => 't1', 'name' => 'Billing']],
        'tickets' => [
            ['source_id' => 'k1', 'title' => 'Refund please', 'description' => 'Charged twice.', 'status' => 'open', 'priority' => 'high', 'requester_source_id' => 'c1', 'tag_source_ids' => ['t1'], 'created_at' => '2024-01-02T03:04:05+00:00'],
            ['source_id' => 'k2', 'title' => 'Login broken', 'status' => 'resolved', 'priority' => 'urgent', 'requester_source_id' => 'c2'],
            ['source_id' => 'k3', 'title' => 'Feature idea', 'status' => 'not-a-status', 'requester_source_id' => 'c1'],
        ],
        'replies' => [
            ['source_id' => 'r1', 'ticket_source_id' => 'k1', 'body' => 'Looking into it', 'author_source_id' => 'c1'],
            ['source_id' => 'r2', 'ticket_source_id' => 'k2', 'body' => 'Internal note', 'is_internal_note' => true],
        ],
    ];

    private const PAGE_SIZE = 2;

    /** @var (\Closure(string, ?string): void)|null */
    public ?\Closure $onExtract = null;

    /** @var list<array{0: string, 1: ?string}> */
    public array $extractCalls = [];

    /** @var array<string, string>|null */
    public ?array $sourceFieldCredentials = null;

    public function name(): string
    {
        return 'fakedesk';
    }

    public function displayName(): string
    {
        return 'FakeDesk';
    }

    public function credentialFields(): array
    {
        return self::CREDENTIAL_FIELDS;
    }

    public function testConnection(array $credentials): bool
    {
        if ('down' === ($credentials['subdomain'] ?? null)) {
            throw new \RuntimeException('FakeDesk is unreachable');
        }

        return self::TOKEN === ($credentials['api_token'] ?? null);
    }

    public function entityTypes(): array
    {
        return array_keys(self::RECORDS);
    }

    public function defaultFieldMappings(string $entityType): array
    {
        return 'tickets' === $entityType ? ['subject' => 'title', 'state' => 'status'] : [];
    }

    public function availableSourceFields(string $entityType, array $credentials): array
    {
        $this->sourceFieldCredentials = $credentials;

        return 'tickets' === $entityType ? self::TICKET_SOURCE_FIELDS : [];
    }

    public function extract(string $entityType, array $credentials, ?string $cursor): ExtractResult
    {
        if (self::TOKEN !== ($credentials['api_token'] ?? null)) {
            throw new \RuntimeException('bad credentials');
        }

        $this->extractCalls[] = [$entityType, $cursor];
        if (null !== $this->onExtract) {
            ($this->onExtract)($entityType, $cursor);
        }

        $records = self::RECORDS[$entityType];
        $offset = (int) ($cursor ?? 0);
        $next = $offset + self::PAGE_SIZE;

        return new ExtractResult(
            \array_slice($records, $offset, self::PAGE_SIZE),
            $next < \count($records) ? (string) $next : null,
            \count($records),
        );
    }
}
