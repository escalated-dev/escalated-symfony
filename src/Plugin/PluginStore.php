<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\PluginStoreRecord;

/**
 * `ctx.config` and `ctx.store`, always for one plugin name.
 *
 * Queries filter in PHP over the plugin's rows in one collection, so a field
 * name never reaches SQL and the operators mean the same on every database.
 */
final class PluginStore
{
    public const CONFIG = '__config__';

    private const FIELD = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)*$/D';

    private const OPERATORS = ['$gt', '$gte', '$lt', '$lte', '$ne', '$in', '$nin'];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, mixed> */
    public function configAll(string $plugin): array
    {
        $data = $this->record($plugin, self::CONFIG, self::CONFIG)?->getData();

        return \is_array($data) ? $data : [];
    }

    public function configGet(string $plugin, string $key): mixed
    {
        return $this->configAll($plugin)[$key] ?? null;
    }

    /**
     * Merges `$data` into the plugin's config.
     *
     * @param array<string, mixed> $data
     */
    public function configSet(string $plugin, array $data): void
    {
        $this->put($plugin, self::CONFIG, self::CONFIG, array_merge($this->configAll($plugin), $data));
    }

    public function get(string $plugin, string $collection, string $key): mixed
    {
        return $this->record($plugin, $collection, $key)?->getData();
    }

    public function set(string $plugin, string $collection, string $key, mixed $value): void
    {
        $this->put($plugin, $collection, $key, $value);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options `limit`, `orderBy`, `order`
     *
     * @return list<array<string, mixed>>
     */
    public function query(string $plugin, string $collection, array $filter = [], array $options = []): array
    {
        $conditions = [];
        foreach ($filter as $field => $condition) {
            $field = (string) $field;
            self::assertField($field);
            if (\is_array($condition) && [] !== $condition && self::isOperatorList($condition)) {
                foreach ($condition as $operator => $operand) {
                    if (!\in_array($operator, self::OPERATORS, true)) {
                        throw new \InvalidArgumentException(sprintf('Unsupported store query operator: %s', $operator));
                    }
                    $conditions[] = [$field, $operator, $operand];
                }
            } else {
                $conditions[] = [$field, '$eq', $condition];
            }
        }

        $orderBy = null;
        if (isset($options['orderBy'])) {
            $orderBy = (string) $options['orderBy'];
            self::assertField($orderBy);
        }

        $rows = [];
        foreach ($this->records($plugin, $collection) as $record) {
            $document = self::document($record);
            foreach ($conditions as [$field, $operator, $operand]) {
                if (!self::matches(self::path($document, $field), $operator, $operand)) {
                    continue 2;
                }
            }
            $rows[] = $document;
        }

        if (null !== $orderBy) {
            $descending = 'desc' === strtolower((string) ($options['order'] ?? 'asc'));
            usort($rows, static function (array $a, array $b) use ($orderBy, $descending): int {
                $x = self::path($a, $orderBy);
                $y = self::path($b, $orderBy);
                // Documents without the field come last either way.
                if ($x === self::missing() || $y === self::missing()) {
                    return ($x === self::missing()) <=> ($y === self::missing());
                }
                $order = self::compareForSort($x, $y);

                return $descending ? -$order : $order;
            });
        }

        if (isset($options['limit']) && is_numeric($options['limit'])) {
            $rows = \array_slice($rows, 0, max(0, (int) $options['limit']));
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function insert(string $plugin, string $collection, array $data): array
    {
        $key = $data['key'] ?? null;
        $record = new PluginStoreRecord($plugin, $collection, null === $key ? null : (string) $key, $data);
        $this->em->persist($record);
        $this->em->flush();

        return self::document($record);
    }

    /**
     * Merges `$data` into the row's data.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(string $plugin, string $collection, string $key, array $data): array
    {
        $record = $this->record($plugin, $collection, $key) ?? throw new \InvalidArgumentException(sprintf('No %s record with key %s.', $collection, $key));
        $existing = $record->getData();
        $record->setData(array_merge(\is_array($existing) ? $existing : [], $data));
        $this->em->flush();

        return self::document($record);
    }

    public function delete(string $plugin, string $collection, string $key): void
    {
        foreach ($this->em->getRepository(PluginStoreRecord::class)->findBy(['plugin' => $plugin, 'collection' => $collection, 'key' => $key]) as $record) {
            $this->em->remove($record);
        }
        $this->em->flush();
    }

    public static function assertField(string $field): void
    {
        if (!preg_match(self::FIELD, $field)) {
            throw new \InvalidArgumentException(sprintf('Invalid store field name: %s', $field));
        }
    }

    private function put(string $plugin, string $collection, string $key, mixed $data): void
    {
        $record = $this->record($plugin, $collection, $key);
        if (null === $record) {
            $this->em->persist(new PluginStoreRecord($plugin, $collection, $key, $data));
        } else {
            $record->setData($data);
        }
        $this->em->flush();
    }

    private function record(string $plugin, string $collection, string $key): ?PluginStoreRecord
    {
        return $this->em->getRepository(PluginStoreRecord::class)->findOneBy(['plugin' => $plugin, 'collection' => $collection, 'key' => $key], ['id' => 'ASC']);
    }

    /**
     * @return list<PluginStoreRecord>
     */
    private function records(string $plugin, string $collection): array
    {
        return $this->em->getRepository(PluginStoreRecord::class)->findBy(['plugin' => $plugin, 'collection' => $collection], ['id' => 'ASC']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(PluginStoreRecord $record): array
    {
        $data = $record->getData();

        return ['_id' => $record->getId()] + (\is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $condition
     */
    private static function isOperatorList(array $condition): bool
    {
        foreach (array_keys($condition) as $key) {
            if (!\is_string($key) || !str_starts_with($key, '$')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The value at a dotted path, or a sentinel when it is missing.
     *
     * @param array<string, mixed> $document
     */
    private static function path(array $document, string $field): mixed
    {
        $value = $document;
        foreach (explode('.', $field) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return self::missing();
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private static function matches(mixed $value, string $operator, mixed $operand): bool
    {
        return match ($operator) {
            '$eq' => self::equals($value, $operand),
            '$ne' => !self::equals($value, $operand),
            '$in' => self::any((array) $operand, static fn (mixed $candidate): bool => self::equals($value, $candidate)),
            '$nin' => !self::any((array) $operand, static fn (mixed $candidate): bool => self::equals($value, $candidate)),
            default => self::compares($value, $operator, $operand),
        };
    }

    /**
     * Equality as MongoDB has it: an array field matches when it holds the
     * value, numbers compare by value, and a missing field equals only null.
     */
    private static function equals(mixed $value, mixed $operand): bool
    {
        if ($value === self::missing()) {
            return null === $operand;
        }
        if (\is_array($value) && array_is_list($value) && !\is_array($operand)) {
            return self::any($value, static fn (mixed $item): bool => self::same($item, $operand));
        }

        return self::same($value, $operand);
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (self::isNumber($a) && self::isNumber($b)) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    /**
     * $gt, $gte, $lt, $lte: numbers with numbers, strings with strings.
     * Anything else, a missing field included, does not match.
     */
    private static function compares(mixed $value, string $operator, mixed $operand): bool
    {
        if (self::isNumber($value) && self::isNumber($operand)) {
            $order = (float) $value <=> (float) $operand;
        } elseif (\is_string($value) && \is_string($operand)) {
            $order = strcmp($value, $operand) <=> 0;
        } else {
            return false;
        }

        return match ($operator) {
            '$gt' => $order > 0,
            '$gte' => $order >= 0,
            '$lt' => $order < 0,
            default => $order <= 0,
        };
    }

    /**
     * Numbers by value, then strings, then everything else (missing last).
     */
    private static function compareForSort(mixed $a, mixed $b): int
    {
        $rank = static fn (mixed $v): int => match (true) {
            self::isNumber($v) => 0,
            \is_string($v) => 1,
            $v === self::missing() => 3,
            default => 2,
        };
        $ra = $rank($a);
        $rb = $rank($b);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }

        return match ($ra) {
            0 => (float) $a <=> (float) $b,
            1 => strcmp($a, $b) <=> 0,
            default => 0,
        };
    }

    /**
     * @param array<mixed> $items
     */
    private static function any(array $items, callable $test): bool
    {
        foreach ($items as $item) {
            if ($test($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stand-in for a field a document does not have.
     */
    private static function missing(): object
    {
        static $missing = null;

        return $missing ??= new \stdClass();
    }

    private static function isNumber(mixed $value): bool
    {
        return \is_int($value) || \is_float($value);
    }
}
