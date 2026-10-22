<?php

declare(strict_types=1);

namespace Escalated\Symfony\Import;

use Escalated\Symfony\Entity\ImportJob;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The default {@see ImportDispatcherInterface}: runs queued imports once the
 * response has been sent (kernel.terminate; under PHP-FPM the client is
 * already answered), or when a console command ends.
 */
class TerminateImportDispatcher implements ImportDispatcherInterface, EventSubscriberInterface
{
    /** @var list<string> */
    private array $queued = [];

    public function __construct(private readonly ImportRunner $runner)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'runQueued',
            ConsoleEvents::TERMINATE => 'runQueued',
        ];
    }

    public function dispatch(ImportJob $job): void
    {
        $this->queued[] = $job->getId();
    }

    public function runQueued(): void
    {
        while ([] !== $this->queued) {
            $this->runner->run(array_shift($this->queued));
        }
    }
}
