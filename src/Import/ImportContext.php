<?php

declare(strict_types=1);

namespace Escalated\Symfony\Import;

/**
 * Import mode, as escalated-laravel's Support\ImportContext. While an import
 * runs, the ticket.created webhook and workflow listeners, SLA attachment and
 * breach checks, escalation rules and automations stand down, so imported
 * history does not fire as if it were new.
 *
 * A static flag, as in Laravel: PHP serves one request per process, and the
 * run happens after the response (kernel.terminate) or in a worker.
 */
final class ImportContext
{
    private static int $depth = 0;

    public static function isImporting(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function suppress(callable $callback): mixed
    {
        ++self::$depth;

        try {
            return $callback();
        } finally {
            --self::$depth;
        }
    }
}
