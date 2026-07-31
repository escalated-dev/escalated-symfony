<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\EventListener\WebhookTicketListener;
use Escalated\Symfony\Service\WebhookDispatcher;
use Escalated\Symfony\Service\WebhookPayloadFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WebhookTicketListenerTest extends TestCase
{
    public function testEnqueuesTicketCreatedWithTicketPayload(): void
    {
        $ticket = (new Ticket())->setSubject('Need help');

        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('enqueue')
            ->with('ticket.created', $this->callback(
                static fn (array $payload): bool => isset($payload['ticket']) && 'Need help' === $payload['ticket']['subject'],
            ));

        $listener = new WebhookTicketListener($dispatcher, $this->payloads(), new NullLogger());
        $listener->handleTicketCreated($ticket);
    }

    public function testDoesNotFlushOrPersistDuringPostPersist(): void
    {
        // The dispatcher's enqueue is the only interaction; the listener must
        // never trigger a nested flush from within postPersist.
        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->expects($this->once())->method('enqueue');
        $dispatcher->expects($this->never())->method('dispatch');
        $dispatcher->expects($this->never())->method('flushPending');

        $listener = new WebhookTicketListener($dispatcher, $this->payloads(), new NullLogger());
        $listener->handleTicketCreated(new Ticket());
    }

    public function testSwallowsErrorsWithWarningLog(): void
    {
        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->method('enqueue')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('boom'));

        $listener = new WebhookTicketListener($dispatcher, $this->payloads(), $logger);
        $listener->handleTicketCreated(new Ticket());
    }

    private function payloads(): WebhookPayloadFactory
    {
        return new WebhookPayloadFactory($this->createMock(EntityManagerInterface::class));
    }
}
