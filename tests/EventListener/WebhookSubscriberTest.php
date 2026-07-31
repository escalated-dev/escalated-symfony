<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\EventListener\WebhookSubscriber;
use Escalated\Symfony\Service\WebhookDispatcher;
use Escalated\Symfony\Service\WebhookPayloadFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;

class WebhookSubscriberTest extends TestCase
{
    public function testSubscribesToTriggerAndBothTerminateEvents(): void
    {
        $events = WebhookSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(TicketWorkflowEvent::class, $events);
        $this->assertSame('onTicketEvent', $events[TicketWorkflowEvent::class]);
        $this->assertArrayHasKey(KernelEvents::TERMINATE, $events);
        $this->assertArrayHasKey(ConsoleEvents::TERMINATE, $events);
    }

    public function testTerminateFlushesPendingDeliveries(): void
    {
        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->expects($this->once())->method('flushPending');

        $subscriber = new WebhookSubscriber($dispatcher, $this->payloads(), new NullLogger());
        $subscriber->onTerminate();
    }

    public function testRepliedMapsToReplyCreatedWithReplyAndAgent(): void
    {
        $calls = [];
        $subscriber = new WebhookSubscriber($this->recordingDispatcher($calls), $this->payloads(), new NullLogger());
        $subscriber->onTicketEvent(new TicketWorkflowEvent('ticket.replied', new Ticket(), [
            'reply_id' => 55,
            'author_id' => 7,
        ]));

        $this->assertCount(1, $calls);
        [$name, $payload] = $calls[0];
        $this->assertSame('reply.created', $name);
        $this->assertSame(55, $payload['reply']['id']);
        $this->assertFalse($payload['reply']['is_internal_note']);
        $this->assertSame(7, $payload['agent_id']);
        $this->assertArrayHasKey('ticket', $payload);
    }

    public function testStatusChangedToResolvedFansOut(): void
    {
        $calls = [];
        $subscriber = new WebhookSubscriber($this->recordingDispatcher($calls), $this->payloads(), new NullLogger());
        $subscriber->onTicketEvent(new TicketWorkflowEvent('ticket.status_changed', new Ticket(), [
            'new_status' => 'resolved',
        ]));

        $this->assertSame(['ticket.status_changed', 'ticket.resolved'], array_column($calls, 0));
    }

    public function testStatusChangedWithoutMappedTargetEmitsOnlyBase(): void
    {
        $calls = [];
        $subscriber = new WebhookSubscriber($this->recordingDispatcher($calls), $this->payloads(), new NullLogger());
        $subscriber->onTicketEvent(new TicketWorkflowEvent('ticket.status_changed', new Ticket(), [
            'new_status' => 'in_progress',
        ]));

        $this->assertSame(['ticket.status_changed'], array_column($calls, 0));
    }

    public function testAssignedCarriesAgentId(): void
    {
        $calls = [];
        $subscriber = new WebhookSubscriber($this->recordingDispatcher($calls), $this->payloads(), new NullLogger());
        $subscriber->onTicketEvent(new TicketWorkflowEvent('ticket.assigned', new Ticket(), [
            'agent_id' => 42,
        ]));

        $this->assertSame('ticket.assigned', $calls[0][0]);
        $this->assertSame(42, $calls[0][1]['agent_id']);
    }

    public function testTaggedEmitsOneEventPerTagWithName(): void
    {
        $calls = [];
        $subscriber = new WebhookSubscriber($this->recordingDispatcher($calls), $this->payloads((new Tag())->setName('urgent')), new NullLogger());
        $subscriber->onTicketEvent(new TicketWorkflowEvent('ticket.tagged', new Ticket(), [
            'tag_ids' => [3],
            'action' => 'added',
        ]));

        $this->assertCount(1, $calls);
        $this->assertSame('ticket.tag_added', $calls[0][0]);
        $this->assertSame(['id' => 3, 'name' => 'urgent'], $calls[0][1]['tag']);
    }

    public function testTaggedRemovedMapsToTagRemoved(): void
    {
        $calls = [];
        $subscriber = new WebhookSubscriber($this->recordingDispatcher($calls), $this->payloads(new Tag()), new NullLogger());
        $subscriber->onTicketEvent(new TicketWorkflowEvent('ticket.tagged', new Ticket(), [
            'tag_ids' => [1],
            'action' => 'removed',
        ]));

        $this->assertSame('ticket.tag_removed', $calls[0][0]);
    }

    public function testUnmappedTriggerEnqueuesNothing(): void
    {
        $calls = [];
        $subscriber = new WebhookSubscriber($this->recordingDispatcher($calls), $this->payloads(), new NullLogger());
        $subscriber->onTicketEvent(new TicketWorkflowEvent('ticket.snoozed', new Ticket()));

        $this->assertSame([], $calls);
    }

    public function testSwallowsErrorsWithWarningLog(): void
    {
        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->method('enqueue')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('boom'));

        $subscriber = new WebhookSubscriber($dispatcher, $this->payloads(), $logger);
        $subscriber->onTicketEvent(new TicketWorkflowEvent('ticket.updated', new Ticket()));
    }

    /**
     * A mock dispatcher whose enqueue() appends ($event, $payload) tuples to the
     * caller-owned $calls array (bound by reference so mutations are visible).
     *
     * @param array<int, array{0: string, 1: array<string, mixed>}> $calls
     */
    private function recordingDispatcher(array &$calls): WebhookDispatcher
    {
        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->method('enqueue')->willReturnCallback(function (string $event, array $payload) use (&$calls): void {
            $calls[] = [$event, $payload];
        });

        return $dispatcher;
    }

    private function payloads(?Tag $tag = null): WebhookPayloadFactory
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($tag);

        return new WebhookPayloadFactory($em);
    }
}
