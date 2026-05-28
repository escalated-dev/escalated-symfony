<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Escalated\Symfony\Contract\TicketActionInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\TicketActionRegistry;
use PHPUnit\Framework\TestCase;

class TicketActionRegistryTest extends TestCase
{
    public function testRegistersConfigActionsAndFindsByKey(): void
    {
        $registry = new TicketActionRegistry([], [
            ['key' => 'sync-crm', 'label' => 'Sync CRM'],
        ]);

        $this->assertNotNull($registry->find('sync-crm'));
        $this->assertNull($registry->find('missing'));
    }

    public function testForTicketSerializesVisibleActionsWithDefaults(): void
    {
        $registry = new TicketActionRegistry([], [
            ['key' => 'sync-crm', 'label' => 'Sync CRM'],
        ]);

        $actions = $registry->forTicket(new Ticket(), null);

        $this->assertCount(1, $actions);
        $this->assertSame([
            'key' => 'sync-crm',
            'label' => 'Sync CRM',
            'variant' => 'secondary',
            'confirmation' => null,
            'disabled' => false,
            'metadata' => [],
        ], $actions[0]);
    }

    public function testOmitsInvisibleActionsAndMarksDisabled(): void
    {
        $registry = new TicketActionRegistry([], [
            ['key' => 'hidden', 'label' => 'Hidden', 'visible' => false],
            ['key' => 'locked', 'label' => 'Locked', 'enabled' => false],
        ]);

        $actions = $registry->forTicket(new Ticket(), null);

        $this->assertCount(1, $actions);
        $this->assertSame('locked', $actions[0]['key']);
        $this->assertTrue($actions[0]['disabled']);
    }

    public function testCollectsTaggedServiceActions(): void
    {
        $tagged = new class implements TicketActionInterface {
            public function getKey(): string
            {
                return 'tagged';
            }

            public function getLabel(Ticket $ticket, mixed $user): string
            {
                return 'Tagged';
            }

            public function isVisible(Ticket $ticket, mixed $user): bool
            {
                return true;
            }

            public function isEnabled(Ticket $ticket, mixed $user): bool
            {
                return true;
            }

            public function getVariant(): string
            {
                return 'primary';
            }

            public function getConfirmation(Ticket $ticket, mixed $user): ?string
            {
                return null;
            }

            public function getMetadata(Ticket $ticket, mixed $user): array
            {
                return ['icon' => 'refresh-cw'];
            }
        };

        $registry = new TicketActionRegistry([$tagged], []);

        $this->assertNotNull($registry->find('tagged'));
        $this->assertSame('primary', $registry->forTicket(new Ticket(), null)[0]['variant']);
    }
}
