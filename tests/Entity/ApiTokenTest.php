<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\ApiToken;
use PHPUnit\Framework\TestCase;

class ApiTokenTest extends TestCase
{
    public function testWildcardGrantsEveryAbility(): void
    {
        $token = (new ApiToken())->setAbilities(['*']);

        $this->assertTrue($token->hasAbility('agent'));
        $this->assertTrue($token->hasAbility('admin'));
        $this->assertTrue($token->hasAbility('anything-else'));
    }

    public function testSpecificAbilityIsScoped(): void
    {
        $token = (new ApiToken())->setAbilities(['agent']);

        $this->assertTrue($token->hasAbility('agent'));
        $this->assertFalse($token->hasAbility('admin'));
    }

    public function testNullOrEmptyAbilitiesGrantNothing(): void
    {
        $this->assertFalse((new ApiToken())->hasAbility('agent'));
        $this->assertFalse((new ApiToken())->setAbilities([])->hasAbility('agent'));
    }

    public function testNullExpiryNeverExpires(): void
    {
        $this->assertFalse((new ApiToken())->isExpired());
    }

    public function testPastExpiryIsExpired(): void
    {
        $token = (new ApiToken())->setExpiresAt(new \DateTimeImmutable('-1 hour'));

        $this->assertTrue($token->isExpired());
    }

    public function testFutureExpiryIsNotExpired(): void
    {
        $token = (new ApiToken())->setExpiresAt(new \DateTimeImmutable('+1 hour'));

        $this->assertFalse($token->isExpired());
    }
}
