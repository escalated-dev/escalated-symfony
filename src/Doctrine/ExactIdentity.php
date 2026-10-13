<?php

declare(strict_types=1);

namespace Escalated\Symfony\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

final class ExactIdentity
{
    /** Expressions are trusted SQL identifiers/placeholders, never input values. */
    public static function equals(AbstractPlatform $platform, string $column, string $value): string
    {
        if ($platform instanceof AbstractMySQLPlatform) {
            return "BINARY $column = BINARY $value";
        }
        $collation = $platform instanceof PostgreSQLPlatform ? '"C"' : 'BINARY';

        return "CAST($column AS TEXT) COLLATE $collation = CAST($value AS TEXT) COLLATE $collation";
    }
}
