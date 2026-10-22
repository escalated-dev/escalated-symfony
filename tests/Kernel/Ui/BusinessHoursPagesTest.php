<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\BusinessSchedule;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Holiday;
use Escalated\Symfony\Entity\SlaPolicy;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * Admin/BusinessHours/Index and Form against what the shared pages read and
 * post (escalated-laravel's Admin\BusinessHoursController).
 */
final class BusinessHoursPagesTest extends EscalatedWebTestCase
{
    /** Every route name the two pages call, from @escalated-dev/escalated 0.12.3. */
    private const PAGE_ROUTES = [
        'escalated.admin.business-hours.index', 'escalated.admin.business-hours.create', 'escalated.admin.business-hours.store',
        'escalated.admin.business-hours.edit', 'escalated.admin.business-hours.update', 'escalated.admin.business-hours.destroy',
    ];

    private const WEEK = [
        'monday' => ['start' => '09:00', 'end' => '17:00'],
        'tuesday' => ['start' => '09:00', 'end' => '17:00'],
        'wednesday' => ['start' => '09:00', 'end' => '17:00'],
        'thursday' => ['start' => '09:00', 'end' => '17:00'],
        'friday' => ['start' => '09:00', 'end' => '13:00'],
        'saturday' => ['start' => '', 'end' => ''],
        'sunday' => ['start' => '', 'end' => ''],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::entityManager();
        $em->persist($admin);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $admin->getId()));
        $em->flush();
        $this->client->loginUser($admin);
    }

    public function testEveryRouteThePagesCallExists(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        self::assertSame([], array_values(array_filter(self::PAGE_ROUTES, static fn (string $name): bool => null === $router->getRouteCollection()->get($name))));
    }

    public function testCreateListEditUpdateAndDeleteASchedule(): void
    {
        $page = $this->visit('/support/admin/business-hours/create');
        self::assertSame('Escalated/Admin/BusinessHours/Form', $page['component']);
        self::assertSame(['timezones'], array_keys($page['props']));
        self::assertContains('America/Toronto', $page['props']['timezones']);
        self::assertContains('UTC', $page['props']['timezones']);

        $this->send('POST', '/support/admin/business-hours', [
            'name' => 'Head office',
            'timezone' => 'America/Toronto',
            'is_default' => true,
            'schedule' => self::WEEK,
            'holidays' => [
                ['name' => 'Christmas', 'date' => '2026-12-25', 'recurring' => true],
                ['name' => 'Retreat', 'date' => '2026-11-06', 'recurring' => false],
            ],
        ]);
        self::assertResponseRedirects('/support/admin/business-hours');
        $em = static::entityManager();
        $em->clear();
        $schedule = $em->getRepository(BusinessSchedule::class)->findOneBy(['name' => 'Head office']);
        self::assertNotNull($schedule);
        self::assertTrue($schedule->isDefault());
        self::assertCount(2, $schedule->getHolidays());

        $page = $this->visit('/support/admin/business-hours');
        self::assertSame('Escalated/Admin/BusinessHours/Index', $page['component']);
        self::assertSame(['schedules'], array_keys($page['props']));
        $row = $page['props']['schedules'][0];
        self::assertSame(['id', 'name', 'timezone', 'is_default', 'schedule', 'holidays', 'created_at', 'updated_at'], array_keys($row));
        self::assertSame('America/Toronto', $row['timezone']);
        self::assertTrue($row['is_default']);
        self::assertSame(self::WEEK, $row['schedule']);
        $holiday = $row['holidays'][0];
        self::assertSame(['id', 'schedule_id', 'name', 'date', 'recurring'], array_keys($holiday));
        self::assertSame($schedule->getId(), $holiday['schedule_id']);
        self::assertSame(['Christmas', '2026-12-25', true], [$holiday['name'], $holiday['date'], $holiday['recurring']]);

        $page = $this->visit('/support/admin/business-hours/'.$schedule->getId().'/edit');
        self::assertSame('Escalated/Admin/BusinessHours/Form', $page['component']);
        self::assertSame(['schedule', 'timezones'], array_keys($page['props']));
        self::assertSame($row['schedule'], $page['props']['schedule']['schedule']);
        self::assertSame('Head office', $page['props']['schedule']['name']);
        self::assertCount(2, $page['props']['schedule']['holidays']);

        // A second default takes the flag; the holidays sent replace the old ones.
        $this->send('POST', '/support/admin/business-hours', ['name' => 'Night desk', 'timezone' => 'UTC', 'is_default' => true, 'schedule' => ['monday' => ['start' => '22:00', 'end' => '06:00']]]);
        $this->send('PUT', '/support/admin/business-hours/'.$schedule->getId(), [
            'name' => 'Toronto office',
            'timezone' => 'America/Toronto',
            'is_default' => false,
            'schedule' => self::WEEK,
            'holidays' => [['name' => 'Boxing Day', 'date' => '2026-12-26T00:00:00.000000Z', 'recurring' => true]],
        ]);
        self::assertResponseRedirects('/support/admin/business-hours');
        $em->clear();
        $schedule = $em->getRepository(BusinessSchedule::class)->find($schedule->getId());
        self::assertSame('Toronto office', $schedule->getName());
        self::assertFalse($schedule->isDefault());
        self::assertSame(['Boxing Day'], array_map(static fn (Holiday $h): string => $h->getName(), $schedule->getHolidays()->toArray()));
        self::assertSame('2026-12-26', $schedule->getHolidays()->first()->getDate()->format('Y-m-d'));
        self::assertCount(1, $em->getRepository(Holiday::class)->findAll());
        $night = $em->getRepository(BusinessSchedule::class)->findOneBy(['name' => 'Night desk']);
        self::assertTrue($night->isDefault());
        self::assertSame(['start' => '22:00', 'end' => '06:00'], $night->getSchedule()['monday']);
        self::assertSame(['start' => '', 'end' => ''], $night->getSchedule()['tuesday']);

        // Policies and departments on a deleted schedule are left without one.
        $policy = (new SlaPolicy())->setName('Business')->setBusinessSchedule($schedule);
        $department = (new Department())->setName('Ops')->setSlug('ops')->setBusinessSchedule($schedule);
        $em->persist($policy);
        $em->persist($department);
        $em->flush();
        $this->send('DELETE', '/support/admin/business-hours/'.$schedule->getId(), []);
        self::assertResponseRedirects('/support/admin/business-hours');
        $em->clear();
        self::assertNull($em->getRepository(BusinessSchedule::class)->find($schedule->getId()));
        self::assertSame([], $em->getRepository(Holiday::class)->findAll());
        self::assertNull($em->getRepository(SlaPolicy::class)->find($policy->getId())->getBusinessSchedule());
        self::assertNull($em->getRepository(Department::class)->find($department->getId())->getBusinessSchedule());
    }

    public function testRejectsAScheduleLaravelWouldReject(): void
    {
        $this->send('POST', '/support/admin/business-hours', [
            'name' => '',
            'timezone' => 'Mars/Olympus',
            'schedule' => 'always',
            'holidays' => [['name' => '', 'date' => 'soon']],
        ]);
        self::assertSame(['name', 'timezone', 'schedule', 'holidays.0.name', 'holidays.0.date'], array_keys($this->lastErrors()));
        self::assertSame([], static::entityManager()->getRepository(BusinessSchedule::class)->findAll());

        $this->send('POST', '/support/admin/business-hours', ['name' => 'Odd', 'timezone' => 'UTC', 'schedule' => ['monday' => ['start' => '9am', 'end' => '17:00']]]);
        self::assertSame(['schedule'], array_keys($this->lastErrors()));
    }

    public function testPoliciesAndDepartmentsTakeAScheduleOnlyWhenOneIsSent(): void
    {
        $em = static::entityManager();
        $schedule = (new BusinessSchedule())->setName('Office');
        $department = (new Department())->setName('Ops')->setSlug('ops');
        $em->persist($schedule);
        $em->persist($department);
        $em->flush();

        $this->send('PUT', '/support/admin/departments/'.$department->getId(), ['name' => 'Ops', 'business_schedule_id' => $schedule->getId()]);
        $this->send('PUT', '/support/admin/departments/'.$department->getId(), ['name' => 'Operations']);
        $em->clear();
        self::assertSame($schedule->getId(), $em->getRepository(Department::class)->find($department->getId())->getBusinessSchedule()?->getId());

        $policyBody = ['name' => 'Business', 'first_response_hours' => ['low' => 4], 'resolution_hours' => ['low' => 8], 'business_hours_only' => true];
        $this->send('POST', '/support/admin/sla-policies', $policyBody + ['business_schedule_id' => $schedule->getId()]);
        $policy = $em->getRepository(SlaPolicy::class)->findOneBy(['name' => 'Business']);
        self::assertSame($schedule->getId(), $policy?->getBusinessSchedule()?->getId());
        $this->send('PUT', '/support/admin/sla-policies/'.$policy->getId(), $policyBody);
        $em->clear();
        self::assertSame($schedule->getId(), $em->getRepository(SlaPolicy::class)->find($policy->getId())->getBusinessSchedule()?->getId());
        self::assertSame($schedule->getId(), $this->visit('/support/admin/sla-policies/'.$policy->getId().'/edit')['props']['policy']['business_schedule_id']);

        $this->send('PUT', '/support/admin/sla-policies/'.$policy->getId(), $policyBody + ['business_schedule_id' => 999]);
        self::assertSame(['business_schedule_id'], array_keys($this->lastErrors()));
        $this->send('PUT', '/support/admin/sla-policies/'.$policy->getId(), $policyBody + ['business_schedule_id' => null]);
        $em->clear();
        self::assertNull($em->getRepository(SlaPolicy::class)->find($policy->getId())->getBusinessSchedule());
    }

    /**
     * @return array<string, string>
     */
    private function lastErrors(): array
    {
        $errors = $this->client->getRequest()->getSession()->getFlashBag()->peek('errors');

        return end($errors) ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
        ], content: (string) json_encode($body));
    }
}
