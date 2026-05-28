<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Escalated\Symfony\Contract\TicketActionInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Support\ArrayTicketAction;

/**
 * Holds the host application's registered custom ticket actions and resolves
 * which are available for a given ticket/user.
 *
 * Actions come from two sources, both injected at container build:
 *   - services implementing {@see TicketActionInterface} (auto-tagged
 *     `escalated.ticket_action`), for dynamic logic;
 *   - plain arrays under `escalated.ticket_actions`, for static buttons.
 */
class TicketActionRegistry
{
    /** @var array<string, TicketActionInterface> */
    private array $actions = [];

    /**
     * @param iterable<TicketActionInterface>  $taggedActions
     * @param array<int, array<string, mixed>> $configActions
     */
    public function __construct(iterable $taggedActions = [], array $configActions = [])
    {
        foreach ($taggedActions as $action) {
            $this->register($action);
        }

        foreach ($configActions as $config) {
            $this->register(new ArrayTicketAction($config));
        }
    }

    public function register(TicketActionInterface $action): void
    {
        $this->actions[$action->getKey()] = $action;
    }

    public function find(string $key): ?TicketActionInterface
    {
        return $this->actions[$key] ?? null;
    }

    /**
     * Returns the actions visible to this ticket/user, serialized for the UI.
     * The controller adds the `url` and `method` before sending to the client.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forTicket(Ticket $ticket, mixed $user): array
    {
        $result = [];

        foreach ($this->actions as $action) {
            if (!$action->isVisible($ticket, $user)) {
                continue;
            }

            $result[] = [
                'key' => $action->getKey(),
                'label' => $action->getLabel($ticket, $user),
                'variant' => $action->getVariant(),
                'confirmation' => $action->getConfirmation($ticket, $user),
                'disabled' => !$action->isEnabled($ticket, $user),
                'metadata' => $action->getMetadata($ticket, $user),
            ];
        }

        return $result;
    }
}
