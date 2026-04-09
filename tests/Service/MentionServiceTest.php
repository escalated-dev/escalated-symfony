<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Escalated\Symfony\Service\MentionService;
use PHPUnit\Framework\TestCase;

class MentionServiceTest extends TestCase
{
    private MentionService $service;

    protected function setUp(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->service = new MentionService($em);
    }

    public function testExtractSingleMention(): void
    {
        $result = $this->service->extractMentions('Hello @john please review');
        $this->assertEquals(['john'], $result);
    }

    public function testExtractMultipleMentions(): void
    {
        $result = $this->service->extractMentions('@alice and @bob please check');
        $this->assertContains('alice', $result);
        $this->assertContains('bob', $result);
    }

    public function testExtractDottedUsername(): void
    {
        $result = $this->service->extractMentions('cc @john.doe');
        $this->assertEquals(['john.doe'], $result);
    }

    public function testDeduplicatesMentions(): void
    {
        $result = $this->service->extractMentions('@alice said @alice should review');
        $this->assertCount(1, $result);
        $this->assertEquals(['alice'], array_values($result));
    }

    public function testEmptyForNoMentions(): void
    {
        $this->assertEmpty($this->service->extractMentions('No mentions here'));
    }

    public function testEmptyForEmptyString(): void
    {
        $this->assertEmpty($this->service->extractMentions(''));
    }
}
