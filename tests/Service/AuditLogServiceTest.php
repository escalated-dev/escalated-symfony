<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\AuditLog;
use Escalated\Symfony\Service\AuditLogService;
use PHPUnit\Framework\TestCase;

class AuditLogServiceTest extends TestCase
{
    private ?AuditLog $persisted = null;

    public function testLogRedactsNestedSensitiveValues(): void
    {
        $service = new AuditLogService($this->entityManager());
        $values = [
            'name' => 'Jane',
            'password' => 'plain-password',
            'profile' => [
                'apiKey' => 'api-key-value',
                'nested' => [
                    ['token' => 'token-value'],
                    ['safe' => 'visible'],
                ],
            ],
            'credentials' => [
                'username' => 'jane',
                'secret' => 'secret-value',
            ],
        ];

        $entry = $service->log('updated', 'user', newValues: $values);

        $this->assertSame($entry, $this->persisted);
        $this->assertSame('[REDACTED]', $entry->getNewValues()['password']);
        $this->assertSame('[REDACTED]', $entry->getNewValues()['profile']['apiKey']);
        $this->assertSame('[REDACTED]', $entry->getNewValues()['profile']['nested'][0]['token']);
        $this->assertSame('[REDACTED]', $entry->getNewValues()['credentials']);
        $this->assertSame('visible', $entry->getNewValues()['profile']['nested'][1]['safe']);
    }

    public function testLogDoesNotMutateOriginalValues(): void
    {
        $service = new AuditLogService($this->entityManager());
        $values = [
            'token' => 'original-token',
            'nested' => ['secret' => 'original-secret'],
        ];

        $service->log('updated', 'user', oldValues: $values);

        $this->assertSame('original-token', $values['token']);
        $this->assertSame('original-secret', $values['nested']['secret']);
        $this->assertSame('[REDACTED]', $this->persisted?->getOldValues()['token']);
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (AuditLog $entry): void {
            $this->persisted = $entry;
        });
        $em->method('flush');
        $em->method('getConnection')->willReturn($this->createMock(Connection::class));

        return $em;
    }
}
