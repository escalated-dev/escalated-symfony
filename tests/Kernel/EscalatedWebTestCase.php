<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sends real HTTP requests through {@see EscalatedTestKernel}: routing, the
 * host firewall, the bundle's request listeners and its controllers.
 */
abstract class EscalatedWebTestCase extends WebTestCase
{
    use CreatesEscalatedKernel;

    /**
     * A client on an in-memory database with the schema created. The kernel is
     * not rebooted between requests, so fixtures persisted in the test are
     * visible to the request and vice versa.
     *
     * @param array<string, mixed> $options
     */
    protected static function clientWithSchema(array $options = []): KernelBrowser
    {
        $client = static::createClient($options);
        $client->disableReboot();

        static::createSchema();

        return $client;
    }
}
