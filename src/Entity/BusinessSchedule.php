<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_business_schedules')]
#[ORM\HasLifecycleCallbacks]
class BusinessSchedule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $timezone = 'UTC';

    #[ORM\Column(type: Types::JSON)]
    private array $hours = [];

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    /** @var Collection<int, Holiday> */
    #[ORM\OneToMany(targetEntity: Holiday::class, mappedBy: 'businessSchedule', cascade: ['persist', 'remove'])]
    private Collection $holidays;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->holidays = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->hours = [
            'monday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => true],
            'tuesday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => true],
            'wednesday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => true],
            'thursday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => true],
            'friday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => true],
            'saturday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => false],
            'sunday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => false],
        ];
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): self
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getHours(): array
    {
        return $this->hours;
    }

    public function setHours(array $hours): self
    {
        $this->hours = $hours;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    /** @return Collection<int, Holiday> */
    public function getHolidays(): Collection
    {
        return $this->holidays;
    }

    public function addHoliday(Holiday $holiday): self
    {
        if (!$this->holidays->contains($holiday)) {
            $this->holidays->add($holiday);
            $holiday->setBusinessSchedule($this);
        }

        return $this;
    }

    public function isWithinBusinessHours(?\DateTimeInterface $dateTime = null): bool
    {
        $dateTime ??= new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
        if ($dateTime->getTimezone()->getName() !== $this->timezone) {
            $dateTime = \DateTimeImmutable::createFromInterface($dateTime)->setTimezone(new \DateTimeZone($this->timezone));
        }

        $dayName = strtolower($dateTime->format('l'));
        $dayConfig = $this->hours[$dayName] ?? null;

        if (!$dayConfig || !($dayConfig['enabled'] ?? false)) {
            return false;
        }

        foreach ($this->holidays as $holiday) {
            if ($holiday->getDate()->format('Y-m-d') === $dateTime->format('Y-m-d')) {
                return false;
            }
        }

        $time = $dateTime->format('H:i');

        return $time >= $dayConfig['start'] && $time <= $dayConfig['end'];
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
