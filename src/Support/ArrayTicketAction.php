<?php

declare(strict_types=1);

namespace Escalated\Symfony\Support;

use Escalated\Symfony\Contract\TicketActionInterface;
use Escalated\Symfony\Entity\Ticket;

/**
 * Wraps a plain config array (from `escalated.ticket_actions`) so it satisfies
 * the {@see TicketActionInterface}. Config-defined actions are static — for
 * dynamic visibility/labels, register a service implementing the interface.
 */
final class ArrayTicketAction implements TicketActionInterface
{
    /**
     * @param array{key: string, label: string, variant?: string, visible?: bool, enabled?: bool, confirmation?: ?string, metadata?: array<string, mixed>} $config
     */
    public function __construct(private readonly array $config)
    {
        if (empty($config['key']) || empty($config['label'])) {
            throw new \InvalidArgumentException('Ticket actions require both "key" and "label" values.');
        }
    }

    public function getKey(): string
    {
        return (string) $this->config['key'];
    }

    public function getLabel(Ticket $ticket, mixed $user): string
    {
        return (string) $this->config['label'];
    }

    public function isVisible(Ticket $ticket, mixed $user): bool
    {
        return (bool) ($this->config['visible'] ?? true);
    }

    public function isEnabled(Ticket $ticket, mixed $user): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function getVariant(): string
    {
        return (string) ($this->config['variant'] ?? 'secondary');
    }

    public function getConfirmation(Ticket $ticket, mixed $user): ?string
    {
        $confirmation = $this->config['confirmation'] ?? null;

        return null === $confirmation ? null : (string) $confirmation;
    }

    public function getMetadata(Ticket $ticket, mixed $user): array
    {
        $metadata = $this->config['metadata'] ?? [];

        return is_array($metadata) ? $metadata : [];
    }
}
