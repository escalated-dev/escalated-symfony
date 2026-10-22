<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Access;

use Escalated\Symfony\Entity\BusinessSchedule;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\SlaPolicy;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\SlaService;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;

/**
 * Which hours a business-hours policy counts in: the policy's schedule, then
 * the ticket department's, then the default one, and with none of them the
 * configured `sla.business_hours`, exactly as before schedules existed.
 */
final class BusinessHoursSlaTest extends EscalatedKernelTestCase
{
    /** Friday 2026-10-23, 16:00 UTC. */
    private const CREATED = '2026-10-23T16:00:00Z';

    public function testTheScheduleIsThePolicysThenTheDepartmentsThenTheDefault(): void
    {
        self::bootKernel();
        self::createSchema();
        $em = self::entityManager();

        $department = (new Department())->setName('Delivery')->setSlug('delivery');
        $policy = (new SlaPolicy())->setName('Business')->setBusinessHoursOnly(true)->setFirstResponseHours(['medium' => 2]);
        $em->persist($department);
        $em->persist($policy);
        $em->flush();
        $ticket = $this->ticket($department);

        // No schedule: the configured hours (Mon-Fri 09:00-17:00 UTC), as before.
        self::assertSame('2026-10-26T10:00:00Z', $this->due($ticket, $policy));

        $this->schedule('Always', ['friday', 'saturday', 'sunday', 'monday'], '00:00', '24:00')->setIsDefault(true);
        $em->flush();
        self::assertSame('2026-10-23T18:00:00Z', $this->due($ticket, $policy));

        $department->setBusinessSchedule($this->schedule('Weekend desk', ['friday', 'saturday']));
        $em->flush();
        self::assertSame('2026-10-24T10:00:00Z', $this->due($ticket, $policy));

        $own = $this->schedule('Sunday desk', ['friday', 'sunday']);
        $policy->setBusinessSchedule($own);
        $em->flush();
        self::assertSame('2026-10-25T10:00:00Z', $this->due($ticket, $policy));

        // An inactive schedule is passed over.
        $own->setIsActive(false);
        $em->flush();
        self::assertSame('2026-10-24T10:00:00Z', $this->due($ticket, $policy));
    }

    public function testAPolicyNotLimitedToBusinessHoursCountsCalendarTime(): void
    {
        self::bootKernel();
        self::createSchema();
        $em = self::entityManager();
        $this->schedule('Default', ['monday'])->setIsDefault(true);
        $policy = (new SlaPolicy())->setName('Calendar')->setFirstResponseHours(['medium' => 3]);
        $em->persist($policy);
        $em->flush();

        self::assertSame('2026-10-23T19:00:00Z', $this->due($this->ticket(null), $policy));
    }

    /**
     * @param list<string> $days
     */
    private function schedule(string $name, array $days, string $start = '09:00', string $end = '17:00'): BusinessSchedule
    {
        $hours = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $hours[$day] = \in_array($day, $days, true) ? ['start' => $start, 'end' => $end] : ['start' => '', 'end' => ''];
        }
        $schedule = (new BusinessSchedule())->setName($name)->setHours($hours);
        self::entityManager()->persist($schedule);
        self::entityManager()->flush();

        return $schedule;
    }

    private function ticket(?Department $department): Ticket
    {
        $service = self::getContainer()->get(TicketService::class);
        \assert($service instanceof TicketService);
        $ticket = $service->create(['subject' => 'Clock', 'department_id' => $department?->getId()]);
        $created = new \ReflectionProperty(Ticket::class, 'createdAt');
        $created->setValue($ticket, new \DateTimeImmutable(self::CREATED));

        return $ticket;
    }

    private function due(Ticket $ticket, SlaPolicy $policy): string
    {
        $sla = self::getContainer()->get(SlaService::class);
        \assert($sla instanceof SlaService);
        $sla->attachPolicy($ticket, $policy);

        return $ticket->getFirstResponseDueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
