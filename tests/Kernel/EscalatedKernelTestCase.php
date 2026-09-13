<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Boots {@see EscalatedTestKernel}. Pass the bundle configuration as
 * `self::bootKernel(['escalated' => [...]])`.
 */
abstract class EscalatedKernelTestCase extends KernelTestCase
{
    use CreatesEscalatedKernel;
}
