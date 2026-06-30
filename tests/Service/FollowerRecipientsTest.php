<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Escalated\Symfony\Service\FollowerRecipients;
use PHPUnit\Framework\TestCase;

final class FollowerRecipientsTest extends TestCase
{
    public function testExcludesActorAndDeduplicates(): void
    {
        self::assertSame(['7', '3'], FollowerRecipients::resolve(['7', '2', '7', '3'], '2'));
    }

    public function testKeepsAllDeduplicatedWhenNoActorExcluded(): void
    {
        self::assertSame(['7', '3'], FollowerRecipients::resolve(['7', '3', '7'], null));
    }

    public function testCoercesIdsToStrings(): void
    {
        self::assertSame(['7'], FollowerRecipients::resolve([7, 2, 7], 2));
    }
}
