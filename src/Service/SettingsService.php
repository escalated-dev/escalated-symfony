<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\EscalatedSetting;
use Escalated\Symfony\Repository\EscalatedSettingRepository;

/**
 * Typed access to the runtime key/value settings store.
 *
 * Mirrors the {@code EscalatedSettings} / {@code SettingsService} pair
 * already in the Laravel / Rails / Django / Adonis ports. Values are
 * stored as strings; this service converts them to/from the type the
 * caller asks for.
 *
 * Persisted in the {@code escalated_settings} table; read at request
 * time so admins can change configuration without a redeploy.
 */
class SettingsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EscalatedSettingRepository $repository,
    ) {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->repository->find($key);

        return $row?->getValue() ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $raw = $this->get($key);

        return is_numeric($raw) ? (int) $raw : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $raw = $this->get($key);
        if ($raw === null) {
            return $default;
        }

        return in_array(strtolower($raw), ['1', 'true', 'on', 'yes'], true);
    }

    public function set(string $key, string $value): void
    {
        $row = $this->repository->find($key);
        if ($row === null) {
            $row = new EscalatedSetting($key, $value);
            $this->em->persist($row);
        } else {
            $row->setValue($value);
        }
        $this->em->flush();
    }

    /**
     * Remove a key. No-op when the key doesn't exist.
     */
    public function delete(string $key): void
    {
        $row = $this->repository->find($key);
        if ($row !== null) {
            $this->em->remove($row);
            $this->em->flush();
        }
    }
}
