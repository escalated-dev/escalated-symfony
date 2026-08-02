<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Escalated\Symfony\Entity\ApiToken;
use Escalated\Symfony\Service\ApiTokenService;
use PHPUnit\Framework\TestCase;

class ApiTokenServiceTest extends TestCase
{
    public function testCreateTokenReturnsPlaintextAndPersistsOnlyTheHash(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(ApiToken::class));
        $em->expects($this->once())->method('flush');

        $service = new ApiTokenService($em);

        $result = $service->createToken(42, 'CI deploy key', ['agent']);

        $plain = $result['plainTextToken'];
        $token = $result['token'];

        // Plaintext is a 64-char hex string (32 random bytes).
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plain);

        // What is stored is the SHA-256 hash, never the plaintext.
        $this->assertSame(hash('sha256', $plain), $token->getToken());
        $this->assertNotSame($plain, $token->getToken());

        $this->assertSame('42', $token->getUserId());
        $this->assertSame('CI deploy key', $token->getName());
        $this->assertSame(['agent'], $token->getAbilities());
        $this->assertNull($token->getExpiresAt());
    }

    public function testCreateTokenGeneratesDistinctTokens(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ApiTokenService($em);

        $a = $service->createToken(1, 'a')['plainTextToken'];
        $b = $service->createToken(1, 'b')['plainTextToken'];

        $this->assertNotSame($a, $b);
    }

    public function testCreateTokenSanitisesAbilities(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ApiTokenService($em);

        $token = $service->createToken(1, 'k', ['agent', 'bogus', 'admin', 'agent'])['token'];

        $this->assertSame(['agent', 'admin'], $token->getAbilities());
    }

    public function testCreateTokenFallsBackToWildcardWhenNoValidAbilities(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ApiTokenService($em);

        $token = $service->createToken(1, 'k', ['nonsense'])['token'];

        $this->assertSame(['*'], $token->getAbilities());
    }

    public function testCreateTokenAppliesExpiry(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ApiTokenService($em);

        $expires = new \DateTimeImmutable('+30 days');
        $token = $service->createToken(1, 'k', ['*'], $expires)['token'];

        $this->assertSame($expires, $token->getExpiresAt());
    }

    public function testFindByPlainTextLooksUpByHash(): void
    {
        $plain = bin2hex(random_bytes(32));
        $stored = (new ApiToken())->setToken(hash('sha256', $plain));

        $repo = $this->createMock(EntityRepository::class);
        $repo->expects($this->once())
            ->method('findOneBy')
            ->with(['token' => hash('sha256', $plain)])
            ->willReturn($stored);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(ApiToken::class)->willReturn($repo);

        $service = new ApiTokenService($em);

        $this->assertSame($stored, $service->findByPlainText($plain));
    }

    public function testFindByPlainTextReturnsNullForEmptyString(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        $service = new ApiTokenService($em);

        $this->assertNull($service->findByPlainText(''));
    }

    public function testTouchLastUsedWritesWhenNeverUsed(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = new ApiTokenService($em);
        $token = new ApiToken();

        $service->touchLastUsed($token, '203.0.113.7');

        $this->assertNotNull($token->getLastUsedAt());
        $this->assertSame('203.0.113.7', $token->getLastUsedIp());
    }

    public function testTouchLastUsedIsThrottled(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $service = new ApiTokenService($em);
        $token = (new ApiToken())->setLastUsedAt(new \DateTimeImmutable('-1 minute'));

        $service->touchLastUsed($token, '203.0.113.7');
    }
}
