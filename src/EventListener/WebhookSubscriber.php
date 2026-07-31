<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventListener;

use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\Service\WebhookDispatcher;
use Escalated\Symfony\Service\WebhookPayloadFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Maps domain events to outbound webhook dispatches.
 *
 * TicketWorkflowEvent dispatches (emitted post-flush by TicketService /
 * AssignmentService / SlaService) are translated into wire event names + a
 * payload here, then buffered on {@see WebhookDispatcher::enqueue()}. Together
 * with WebhookTicketListener (which covers ticket.created via Doctrine
 * postPersist) this catches every event the Symfony backend currently emits.
 *
 * The buffer is drained on terminate — both {@see KernelEvents::TERMINATE}
 * (HTTP) and {@see ConsoleEvents::TERMINATE} (cron/CLI, e.g. SLA breach scans)
 * — so delivery never blocks the originating request and never runs mid-flush.
 *
 * Mapping errors are caught + warn-logged so a webhook problem never disrupts
 * the mutation that fired the event.
 */
final class WebhookSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly WebhookPayloadFactory $payloads,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TicketWorkflowEvent::class => 'onTicketEvent',
            KernelEvents::TERMINATE => 'onTerminate',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onTicketEvent(TicketWorkflowEvent $event): void
    {
        try {
            foreach ($this->map($event) as [$name, $payload]) {
                $this->dispatcher->enqueue($name, $payload);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[Escalated\\WebhookSubscriber] failed to enqueue %s webhook for ticket #%s: %s',
                $event->triggerName,
                $event->ticket->getId() ?? '?',
                $e->getMessage(),
            ));
        }
    }

    public function onTerminate(): void
    {
        try {
            $this->dispatcher->flushPending();
        } catch (\Throwable $e) {
            $this->logger->warning('[Escalated\\WebhookSubscriber] webhook flush failed: '.$e->getMessage());
        }
    }

    /**
     * Translate an internal trigger name into zero or more wire events. A
     * single trigger can fan out (e.g. a status change to "resolved" emits both
     * ticket.status_changed and ticket.resolved; a multi-tag change emits one
     * event per tag).
     *
     * @return iterable<array{0: string, 1: array<string, mixed>}>
     */
    private function map(TicketWorkflowEvent $event): iterable
    {
        $ticket = $event->ticket;
        $context = $event->context;
        $base = $this->payloads->ticket($ticket);

        switch ($event->triggerName) {
            case 'ticket.updated':
                yield ['ticket.updated', $base];
                break;

            case 'ticket.priority_changed':
                yield ['ticket.priority_changed', $base];
                break;

            case 'ticket.status_changed':
                yield ['ticket.status_changed', $base];
                $derived = match ($context['new_status'] ?? null) {
                    'resolved' => 'ticket.resolved',
                    'closed' => 'ticket.closed',
                    'reopened' => 'ticket.reopened',
                    'escalated' => 'ticket.escalated',
                    default => null,
                };
                if (null !== $derived) {
                    yield [$derived, $base];
                }
                break;

            case 'ticket.assigned':
                $payload = $base;
                if (\array_key_exists('agent_id', $context)) {
                    $payload['agent_id'] = $context['agent_id'];
                }
                yield ['ticket.assigned', $payload];
                break;

            case 'ticket.replied':
                $payload = $base;
                $payload['reply'] = [
                    'id' => $context['reply_id'] ?? null,
                    'is_internal_note' => false,
                ];
                if (\array_key_exists('author_id', $context)) {
                    $payload['agent_id'] = $context['author_id'];
                }
                yield ['reply.created', $payload];
                break;

            case 'ticket.tagged':
                $name = 'removed' === ($context['action'] ?? 'added') ? 'ticket.tag_removed' : 'ticket.tag_added';
                foreach ($context['tag_ids'] ?? [] as $tagId) {
                    $payload = $base;
                    $payload['tag'] = $this->payloads->tag((int) $tagId);
                    yield [$name, $payload];
                }
                break;

            case 'sla.breached':
                yield ['sla.breached', $base];
                break;

            case 'sla.warning':
                yield ['sla.warning', $base];
                break;

            default:
                // Unmapped trigger — no webhook.
        }
    }
}
