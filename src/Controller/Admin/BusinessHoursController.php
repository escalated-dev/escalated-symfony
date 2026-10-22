<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Entity\BusinessSchedule;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Holiday;
use Escalated\Symfony\Entity\SlaPolicy;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\BusinessHoursCalculator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Escalated/Admin/BusinessHours/Index and Form, as escalated-laravel's
 * Admin\BusinessHoursController: schedules with their weekly hours, time zone
 * and holidays. SlaService counts business-hours deadlines in them.
 */
#[Route('/admin/business-hours', name: 'escalated.admin.business-hours.')]
class BusinessHoursController extends UiController
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/BusinessHours/Index', [
            'schedules' => array_map(
                fn (BusinessSchedule $s): array => self::serialize($s),
                $this->em->getRepository(BusinessSchedule::class)->findBy([], ['id' => 'ASC']),
            ),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/BusinessHours/Form', [
            'timezones' => BusinessHoursCalculator::timezones(),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $schedule = new BusinessSchedule();
        if ([] !== $errors = $this->apply($schedule, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.business-hours.create');
        }
        $this->em->persist($schedule);
        $this->em->flush();

        $this->addFlash('success', 'Business schedule created.');

        return $this->redirectToRoute('escalated.admin.business-hours.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/BusinessHours/Form', [
            'schedule' => self::serialize($this->find($id)),
            'timezones' => BusinessHoursCalculator::timezones(),
        ]);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $schedule = $this->find($id);
        if ([] !== $errors = $this->apply($schedule, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.business-hours.edit', ['id' => $id]);
        }
        $this->em->flush();

        $this->addFlash('success', 'Business schedule updated.');

        return $this->redirectToRoute('escalated.admin.business-hours.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $schedule = $this->find($id);
        // Policies and departments on it fall back to the default schedule.
        foreach ([SlaPolicy::class, Department::class] as $entity) {
            $this->em->createQueryBuilder()
                ->update($entity, 'e')
                ->set('e.businessSchedule', 'NULL')
                ->where('e.businessSchedule = :schedule')
                ->setParameter('schedule', $schedule)
                ->getQuery()
                ->execute();
        }
        $this->em->remove($schedule);
        $this->em->flush();

        $this->addFlash('success', 'Business schedule deleted.');

        return $this->redirectToRoute('escalated.admin.business-hours.index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * A schedule as the shared pages read it.
     *
     * @return array<string, mixed>
     */
    public static function serialize(BusinessSchedule $s): array
    {
        return [
            'id' => $s->getId(),
            'name' => $s->getName(),
            'timezone' => $s->getTimezone(),
            'is_default' => $s->isDefault(),
            'schedule' => $s->getSchedule(),
            'holidays' => array_values(array_map(static fn (Holiday $h): array => [
                'id' => $h->getId(),
                'schedule_id' => $s->getId(),
                'name' => $h->getName(),
                'date' => $h->getDate()->format('Y-m-d'),
                'recurring' => $h->isRecurring(),
            ], $s->getHolidays()->toArray())),
            'created_at' => $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $s->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function find(int $id): BusinessSchedule
    {
        $schedule = $this->em->getRepository(BusinessSchedule::class)->find($id);
        if (null === $schedule) {
            throw $this->createNotFoundException('Business schedule not found.');
        }

        return $schedule;
    }

    /**
     * Laravel's rules: a name, a known time zone, the weekly hours, and
     * holidays each with a name and a date. Saving a default schedule makes
     * it the only one; the holidays sent replace the ones kept.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function apply(BusinessSchedule $schedule, array $payload): array
    {
        $errors = [];

        $name = \is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        if ('' === $name) {
            $errors['name'] = 'The name field is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'The name field must not be greater than 255 characters.';
        }

        $timezone = $payload['timezone'] ?? null;
        if (!BusinessHoursCalculator::isValidTimezone($timezone)) {
            $errors['timezone'] = 'The timezone field must be a valid timezone.';
        }

        $hours = [];
        $given = $payload['schedule'] ?? null;
        if (!\is_array($given)) {
            $errors['schedule'] = 'The schedule field is required.';
        } else {
            foreach (self::DAYS as $day) {
                $row = \is_array($given[$day] ?? null) ? $given[$day] : [];
                $start = \is_string($row['start'] ?? null) ? trim($row['start']) : '';
                $end = \is_string($row['end'] ?? null) ? trim($row['end']) : '';
                foreach ([$start, $end] as $time) {
                    if ('' !== $time && 1 !== preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$|^24:00$/D', $time)) {
                        $errors['schedule'] = 'Opening hours must be times like 09:00.';
                    }
                }
                $hours[$day] = ['start' => $start, 'end' => $end];
            }
        }

        $holidays = [];
        $givenHolidays = $payload['holidays'] ?? null;
        if (null !== $givenHolidays && !\is_array($givenHolidays)) {
            $errors['holidays'] = 'The holidays field must be an array.';
        }
        foreach (\is_array($givenHolidays) ? array_values($givenHolidays) : [] as $i => $holiday) {
            $holidayName = \is_array($holiday) && \is_string($holiday['name'] ?? null) ? trim($holiday['name']) : '';
            if ('' === $holidayName || mb_strlen($holidayName) > 255) {
                $errors["holidays.$i.name"] = 'The holiday name field is required.';
            }
            $date = \is_array($holiday) ? self::date($holiday['date'] ?? null) : null;
            if (null === $date) {
                $errors["holidays.$i.date"] = 'The holiday date field must be a valid date.';
            }
            if (null !== $date && '' !== $holidayName) {
                $holidays[] = (new Holiday())
                    ->setName($holidayName)
                    ->setDate($date)
                    ->setIsRecurring(filter_var($holiday['recurring'] ?? false, \FILTER_VALIDATE_BOOL));
            }
        }

        if ([] !== $errors) {
            return $errors;
        }

        $isDefault = filter_var($payload['is_default'] ?? false, \FILTER_VALIDATE_BOOL);
        if ($isDefault) {
            foreach ($this->em->getRepository(BusinessSchedule::class)->findBy(['isDefault' => true]) as $other) {
                if ($other !== $schedule) {
                    $other->setIsDefault(false);
                }
            }
        }

        $schedule->setName($name)
            ->setTimezone((string) $timezone)
            ->setIsDefault($isDefault)
            ->setHours($hours);
        foreach ($schedule->getHolidays()->toArray() as $old) {
            $schedule->removeHoliday($old);
        }
        foreach ($holidays as $holiday) {
            $schedule->addHoliday($holiday);
        }

        return [];
    }

    /** A "Y-m-d" date, or the date part of a timestamp the page sent back. */
    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^(\d{4}-\d{2}-\d{2})(?:$|[T ])/', $value, $m)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);

        return false !== $date && $date->format('Y-m-d') === $m[1] ? $date : null;
    }
}
