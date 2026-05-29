<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Doctrine;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Escalated\Symfony\Doctrine\UserIdType;
use PHPUnit\Framework\TestCase;

class UserIdTypeTest extends TestCase
{
    private ?string $previousEnv = null;

    protected function tearDown(): void
    {
        if (null === $this->previousEnv) {
            unset($_ENV['ESCALATED_USER_KEY_TYPE']);
        } else {
            $_ENV['ESCALATED_USER_KEY_TYPE'] = $this->previousEnv;
        }

        parent::tearDown();
    }

    public function testDefaultKeyTypeUsesIntegerSqlAndReturnsIntFromNumericString(): void
    {
        unset($_ENV['ESCALATED_USER_KEY_TYPE']);
        $this->previousEnv = null;

        $type = new UserIdType();
        $platform = new SQLitePlatform();

        $sql = $type->getSQLDeclaration([], $platform);
        $this->assertStringContainsStringIgnoringCase('INTEGER', $sql);
        $this->assertSame(5, $type->convertToPHPValue('5', $platform));
    }

    public function testUuidKeyTypeUsesStringSqlAndReturnsStringValue(): void
    {
        $this->previousEnv = $_ENV['ESCALATED_USER_KEY_TYPE'] ?? null;
        $_ENV['ESCALATED_USER_KEY_TYPE'] = 'uuid';

        $type = new UserIdType();
        $platform = new SQLitePlatform();

        $sql = $type->getSQLDeclaration([], $platform);
        $this->assertStringContainsStringIgnoringCase('CHAR', $sql);
        $this->assertSame('abc-1', $type->convertToPHPValue('abc-1', $platform));
    }
}
