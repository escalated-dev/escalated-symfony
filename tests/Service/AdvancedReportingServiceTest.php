<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Escalated\Symfony\Service\AdvancedReportingService;
use PHPUnit\Framework\TestCase;

class AdvancedReportingServiceTest extends TestCase
{
    public function testPercentileCalculation(): void
    {
        $service = new \ReflectionClass(AdvancedReportingService::class);
        $method = $service->getMethod('percentiles');
        $method->setAccessible(true);

        // Create a mock instance
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $instance = new AdvancedReportingService($em);

        $result = $method->invoke($instance, [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0]);

        $this->assertArrayHasKey('p50', $result);
        $this->assertArrayHasKey('p75', $result);
        $this->assertArrayHasKey('p90', $result);
        $this->assertArrayHasKey('p95', $result);
        $this->assertArrayHasKey('p99', $result);
        $this->assertEquals(5.5, $result['p50']);
    }

    public function testBuildDistributionEmpty(): void
    {
        $service = new \ReflectionClass(AdvancedReportingService::class);
        $method = $service->getMethod('buildDistribution');
        $method->setAccessible(true);

        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $instance = new AdvancedReportingService($em);

        $result = $method->invoke($instance, [], 'hours');
        $this->assertEmpty($result['buckets']);
        $this->assertEmpty($result['stats']);
    }

    public function testCompositeScoreCalculation(): void
    {
        $service = new \ReflectionClass(AdvancedReportingService::class);
        $method = $service->getMethod('compositeScore');
        $method->setAccessible(true);

        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $instance = new AdvancedReportingService($em);

        $score = $method->invoke($instance, 80.0, 2.0, 24.0, 4.5);
        $this->assertIsFloat($score);
        $this->assertGreaterThan(0, $score);
    }
}
