<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Doctrine\ORM\Mapping\Column;
use Escalated\Symfony\Entity\Ticket;
use PHPUnit\Framework\TestCase;

/**
 * TicketService::create() inserts a ticket with a temporary reference and
 * replaces it with ESC-00042 once the id exists. The temporary value was
 * 'TEMP-' plus a 36-character UUID -- 41 characters in a VARCHAR(32) column.
 * SQLite ignores the length; MySQL and PostgreSQL reject the insert, so no
 * ticket could be created there through the service (API, portal or inbound
 * email).
 */
final class TicketReferenceTest extends TestCase
{
    public function testTheTemporaryReferenceFitsTheReferenceColumn(): void
    {
        $ticket = new Ticket();
        $ticket->onPrePersist();

        self::assertStringStartsWith('TEMP-', $ticket->getReference());
        self::assertLessThanOrEqual(
            $this->referenceColumnLength(),
            \strlen($ticket->getReference()),
            'the temporary reference must fit escalated_tickets.reference',
        );
    }

    public function testTemporaryReferencesAreUnique(): void
    {
        $first = new Ticket();
        $first->onPrePersist();
        $second = new Ticket();
        $second->onPrePersist();

        self::assertNotSame($first->getReference(), $second->getReference());
    }

    public function testAnExplicitReferenceIsKept(): void
    {
        $ticket = (new Ticket())->setReference('ESC-00042');
        $ticket->onPrePersist();

        self::assertSame('ESC-00042', $ticket->getReference());
    }

    private function referenceColumnLength(): int
    {
        $attributes = (new \ReflectionProperty(Ticket::class, 'reference'))->getAttributes(Column::class);
        self::assertCount(1, $attributes);

        $length = $attributes[0]->newInstance()->length;
        self::assertIsInt($length);

        return $length;
    }
}
