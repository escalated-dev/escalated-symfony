<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventListener;

use Escalated\Symfony\Event\EventNames;
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
        foreach (EventNames::forEvent($event) as $name) {
            yield from $this->payloadsFor($name, $event);
        }
    }

    /**
     * @return iterable<array{0: string, 1: array<string, mixed>}>
     */
    private function payloadsFor(string $name, TicketWorkflowEvent $event): iterable
    {
        $context = $event->context;
        $base = $this->payloads->ticket($event->ticket);

        switch ($name) {
            case EventNames::TICKET_UPDATED:
            case EventNames::PRIORITY_CHANGED:
            case EventNames::STATUS_CHANGED:
            case EventNames::TICKET_RESOLVED:
            case EventNames::TICKET_CLOSED:
            case EventNames::TICKET_REOPENED:
            case EventNames::TICKET_ESCALATED:
            case EventNames::SLA_BREACHED:
            case EventNames::SLA_WARNING:
                yield [$name, $base];
                break;

            case EventNames::TICKET_ASSIGNED:
                $payload = $base;
                if (\array_key_exists('agent_id', $context)) {
                    $payload['agent_id'] = $context['agent_id'];
                }
                yield [$name, $payload];
                break;

            case EventNames::REPLY_CREATED:
            case EventNames::NOTE_CREATED:
                $payload = $base;
                $payload['reply'] = [
                    'id' => $context['reply_id'] ?? null,
                    'is_internal_note' => EventNames::NOTE_CREATED === $name,
                ];
                if (\array_key_exists('author_id', $context)) {
                    $payload['agent_id'] = $context['author_id'];
                }
                yield [$name, $payload];
                break;

            case EventNames::TICKET_UNASSIGNED:
                $payload = $base;
                $payload['previous_agent_id'] = $context['previous_agent_id'] ?? null;
                yield [$name, $payload];
                break;

            case EventNames::DEPARTMENT_CHANGED:
                $payload = $base;
                $payload['old_department_id'] = $context['old_department_id'] ?? null;
                $payload['new_department_id'] = $context['new_department_id'] ?? null;
                yield [$name, $payload];
                break;

            case EventNames::TAG_ADDED:
            case EventNames::TAG_REMOVED:
                foreach ($context['tag_ids'] ?? [] as $tagId) {
                    $payload = $base;
                    $payload['tag'] = $this->payloads->tag((int) $tagId);
                    yield [$name, $payload];
                }
                break;

            case EventNames::CHAT_STARTED:
            case EventNames::CHAT_ASSIGNED:
            case EventNames::CHAT_MESSAGE:
            case EventNames::CHAT_ENDED:
                // The session's id, never the visitor's chat token.
                $payload = $base;
                foreach (['chat_session_id', 'agent_id', 'reply_id', 'is_agent', 'ended_by', 'reason'] as $key) {
                    if (\array_key_exists($key, $context)) {
                        $payload[$key] = $context[$key];
                    }
                }
                yield [$name, $payload];
                break;

            default:
                // Not a webhook event.
        }
    }
}
