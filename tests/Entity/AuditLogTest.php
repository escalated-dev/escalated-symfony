<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\AuditLog;
use PHPUnit\Framework\TestCase;

class AuditLogTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $log = new AuditLog();
        $log->setAction('ticket.updated');
        $log->setEntityType('ticket');
        $log->setEntityId(42);
        $log->setPerformerType('agent');
        $log->setPerformerId(5);
        $log->setOldValues(['status' => 'open']);
        $log->setNewValues(['status' => 'resolved']);
        $log->setIpAddress('127.0.0.1');
        $log->setUserAgent('Mozilla/5.0');

        $this->assertSame('ticket.updated', $log->getAction());
        $this->assertSame('ticket', $log->getEntityType());
        $this->assertSame(42, $log->getEntityId());
        $this->assertSame('agent', $log->getPerformerType());
        $this->assertSame(5, $log->getPerformerId());
        $this->assertSame(['status' => 'open'], $log->getOldValues());
        $this->assertSame(['status' => 'resolved'], $log->getNewValues());
        $this->assertSame('127.0.0.1', $log->getIpAddress());
        $this->assertSame('Mozilla/5.0', $log->getUserAgent());
        $this->assertInstanceOf(\DateTimeImmutable::class, $log->getCreatedAt());
    }
}
