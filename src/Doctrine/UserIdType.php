<?php

declare(strict_types=1);

namespace Escalated\Symfony\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Doctrine type for columns that store a *host-app* user id. The host user
 * primary key may be an integer (default) or a UUID/string. The SQL column type
 * is chosen from ESCALATED_USER_KEY_TYPE (int|bigint|uuid|string), defaulting to
 * integer so existing integer-keyed installs get an identical schema.
 */
final class UserIdType extends Type
{
    public const NAME = 'escalated_user_id';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return match (self::keyType()) {
            'uuid', 'string' => $platform->getStringTypeDeclarationSQL(['length' => 255]),
            'bigint' => $platform->getBigIntTypeDeclarationSQL($column),
            default => $platform->getIntegerTypeDeclarationSQL($column),
        };
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): int|string|null
    {
        if (null === $value) {
            return null;
        }

        $type = self::keyType();
        if (('int' === $type || 'bigint' === $type) && is_numeric($value)) {
            return (int) $value;
        }

        return (string) $value;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): int|string|null
    {
        return $value;
    }

    private static function keyType(): string
    {
        $raw = strtolower(trim((string) ($_ENV['ESCALATED_USER_KEY_TYPE'] ?? getenv('ESCALATED_USER_KEY_TYPE') ?: 'int')));

        return match ($raw) {
            'bigint' => 'bigint',
            'uuid' => 'uuid',
            'string', 'varchar' => 'string',
            default => 'int',
        };
    }
}
