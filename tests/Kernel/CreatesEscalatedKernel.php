<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Shared by the kernel and web test cases: builds {@see EscalatedTestKernel}
 * from the options passed to bootKernel() / createClient(), and creates the
 * schema from the mapped entities.
 *
 * Options:
 *  - `escalated`:  the `escalated:` configuration block
 *  - `extensions`: extra extension configs, keyed by extension name
 *  - `host_user`:  map the fixture host user entity (default true)
 */
trait CreatesEscalatedKernel
{
    protected static function getKernelClass(): string
    {
        return EscalatedTestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new EscalatedTestKernel(
            $options['environment'] ?? 'test',
            (bool) ($options['debug'] ?? false),
            $options['escalated'] ?? [],
            $options['extensions'] ?? [],
            (bool) ($options['host_user'] ?? true),
        );
    }

    protected static function entityManager(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * Create every mapped table on the kernel's connection, starting from an
     * empty database.
     *
     * dropDatabase() rather than dropSchema(): on a persistent database the
     * previous test may have left tables the mapping does not know (the
     * migrations metadata table, say) holding foreign keys, and dropSchema()
     * silently skips drops that fail.
     */
    protected static function createSchema(): void
    {
        $em = static::entityManager();

        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }
}
