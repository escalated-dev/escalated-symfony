<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Event\EventNames;
use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\Import\ImportContext;
use Escalated\Symfony\Plugin\PluginPayloads;
use Escalated\Symfony\Plugin\PluginRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Delivers the canonical events (decisions/2026-10-21-event-vocabulary.md) to
 * active plugins' actions, under exactly those names: what webhooks and
 * workflows are told, plugins are told. Legacy names are never delivered.
 *
 * Events are collected while the request runs and delivered on terminate
 * (HTTP and console), after the change is written and the response sent, so
 * a slow or failing plugin never fails or delays the request that caused it.
 * `ticket.created` comes from Doctrine's postPersist, as for webhooks, so
 * tickets created on any path are announced; its payload is built at
 * delivery, when the ticket has its reference.
 */
#[AsDoctrineListener(event: Events::postPersist)]
class PluginEventRelay implements EventSubscriberInterface
{
    private const MAX_ROUNDS = 5;

    /** @var list<array{0: string, 1: Ticket, 2: array<string, mixed>}> */
    private array $pending = [];

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginPayloads $payloads,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TicketWorkflowEvent::class => 'onTicketEvent',
            KernelEvents::TERMINATE => ['flush', -16],
            ConsoleEvents::TERMINATE => ['flush', -16],
        ];
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        // An imported ticket is history, not a new ticket.
        if ($entity instanceof Ticket && !ImportContext::isImporting()) {
            $this->pending[] = [EventNames::TICKET_CREATED, $entity, []];
        }
    }

    public function onTicketEvent(TicketWorkflowEvent $event): void
    {
        foreach (EventNames::forEvent($event) as $name) {
            if (\in_array($name, EventNames::CANONICAL, true)) {
                $this->pending[] = [$name, $event->ticket, $event->context];
            }
        }
    }

    /**
     * Delivers what was collected. Called on terminate.
     */
    public function flush(): void
    {
        // What plugins do in response is announced too, a few rounds deep,
        // so two plugins answering each other cannot loop forever.
        for ($round = 0; [] !== $this->pending; ++$round) {
            $pending = $this->pending;
            if ($round >= self::MAX_ROUNDS) {
                $this->pending = [];
                $this->logger->warning(sprintf('[Escalated plugins] %d events caused by plugins %d rounds deep were not delivered.', \count($pending), $round));

                return;
            }
            $this->pending = [];

            try {
                if ([] === $this->plugins->activePlugins()) {
                    continue;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[Escalated plugins] could not read the active plugins; events not delivered: '.$e->getMessage());

                return;
            }

            foreach ($pending as [$name, $ticket, $context]) {
                try {
                    foreach ($this->payloadsFor($name, $ticket, $context) as $payload) {
                        $this->plugins->dispatchAction($name, $payload);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning(sprintf('[Escalated plugins] %s for ticket #%s was not delivered: %s', $name, $ticket->getId() ?? '?', $e->getMessage()));
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return iterable<array<string, mixed>>
     */
    private function payloadsFor(string $name, Ticket $ticket, array $context): iterable
    {
        if (str_starts_with($name, 'chat.')) {
            $extras = array_intersect_key($context, array_flip(['agent_id', 'from_agent_id', 'to_agent_id', 'to_department_id', 'reply_id', 'is_agent', 'ended_by', 'reason']));
            yield $this->payloads->chat($ticket, $context['chat_session_id'] ?? null, $extras);

            return;
        }

        $flat = $this->payloads->ticket($ticket);
        $base = $flat + ['ticket' => $flat];

        switch ($name) {
            case EventNames::STATUS_CHANGED:
                yield $base + ['old_status' => $context['old_status'] ?? null, 'new_status' => $context['new_status'] ?? null];
                break;

            case EventNames::TICKET_ASSIGNED:
                yield $base + ['agent_id' => $context['agent_id'] ?? $ticket->getAssignedTo()];
                break;

            case EventNames::TICKET_UNASSIGNED:
                yield $base + ['previous_agent_id' => $context['previous_agent_id'] ?? null];
                break;

            case EventNames::PRIORITY_CHANGED:
                yield $base + ['old_priority' => $context['old_priority'] ?? null, 'new_priority' => $context['new_priority'] ?? null];
                break;

            case EventNames::DEPARTMENT_CHANGED:
                yield $base + ['old_department_id' => $context['old_department_id'] ?? null, 'new_department_id' => $context['new_department_id'] ?? null];
                break;

            case EventNames::TAG_ADDED:
            case EventNames::TAG_REMOVED:
                foreach ($context['tag_ids'] ?? [] as $tagId) {
                    $tag = $this->em->find(Tag::class, (int) $tagId);
                    yield $base + ['tag' => null === $tag ? ['id' => (int) $tagId, 'name' => null, 'slug' => null] : $this->payloads->tag($tag)];
                }
                break;

            case EventNames::REPLY_CREATED:
            case EventNames::NOTE_CREATED:
                $reply = isset($context['reply_id']) ? $this->em->find(Reply::class, (int) $context['reply_id']) : null;
                if (null === $reply) {
                    break;
                }
                $data = $this->payloads->reply($reply);
                yield $data + ['reply' => $data, 'ticket' => $flat];
                break;

            case EventNames::SLA_WARNING:
            case EventNames::SLA_BREACHED:
                yield $base + ['type' => $context['type'] ?? $context['breach_type'] ?? null];
                break;

            case EventNames::TICKET_UPDATED:
                yield $base + (\is_array($context['changes'] ?? null) ? ['changes' => array_intersect_key($context['changes'], array_flip(['subject', 'description', 'priority', 'ticket_type', 'metadata']))] : []);
                break;

            default:
                yield $base;
        }
    }
}
