<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\CannedResponse;
use PHPUnit\Framework\TestCase;

class CannedResponseTest extends TestCase
{
    public function testDefaults(): void
    {
        $response = new CannedResponse();

        $this->assertNull($response->getId());
        $this->assertSame('', $response->getTitle());
        $this->assertSame('', $response->getBody());
        $this->assertNull($response->getCategory());
        $this->assertTrue($response->isShared());
        $this->assertNull($response->getCreatedBy());
    }

    public function testFluentSetters(): void
    {
        $response = new CannedResponse();
        $result = $response->setTitle('Refund policy')
            ->setBody('Our refund window is 30 days.')
            ->setCategory('billing')
            ->setIsShared(false)
            ->setCreatedBy(42);

        $this->assertSame($response, $result);
        $this->assertSame('Refund policy', $response->getTitle());
        $this->assertSame('Our refund window is 30 days.', $response->getBody());
        $this->assertSame('billing', $response->getCategory());
        $this->assertFalse($response->isShared());
        $this->assertSame(42, $response->getCreatedBy());
    }

    public function testCategoryIsNullable(): void
    {
        $response = (new CannedResponse())->setCategory('support')->setCategory(null);

        $this->assertNull($response->getCategory());
    }

    public function testTimestampsSetOnConstruction(): void
    {
        $response = new CannedResponse();

        $this->assertInstanceOf(\DateTimeImmutable::class, $response->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $response->getUpdatedAt());
    }

    public function testTouchUpdatedAtAdvancesTimestamp(): void
    {
        $response = new CannedResponse();
        $before = $response->getUpdatedAt();

        $response->touchUpdatedAt();

        $this->assertGreaterThanOrEqual($before, $response->getUpdatedAt());
    }
}
