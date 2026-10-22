<?php

declare(strict_types=1);

namespace Escalated\Symfony\Import;

use Escalated\Symfony\Entity\ImportJob;

/**
 * Queues an import to run outside the request that started it, as
 * escalated-laravel dispatches ImportService::run onto its queue.
 *
 * The default, {@see TerminateImportDispatcher}, runs it after the response
 * is sent. A host with Messenger or another queue aliases this interface to
 * its own implementation and calls {@see ImportRunner::run()} (or the
 * `escalated:import:run` command) from its worker.
 */
interface ImportDispatcherInterface
{
    public function dispatch(ImportJob $job): void;
}
