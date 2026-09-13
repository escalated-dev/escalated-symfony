<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Boots {@see EscalatedTestKernel}. Pass the bundle configuration as
 * `self::bootKernel(['escalated' => [...]])`.
 */
abstract class EscalatedKernelTestCase extends KernelTestCase
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
        );
    }
}
