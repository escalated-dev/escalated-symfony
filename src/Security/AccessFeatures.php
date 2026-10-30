<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

/**
 * The install-level access switches (ADR 2026-10-29-configurable-access-features).
 *
 * Read from configuration (`escalated.access.*`, or the ESCALATED_* env var)
 * only, never from the settings table: each one changes who may sign in or
 * what they may do, so it must not be something a stored setting can flip.
 *
 * SSO is not enforced by this bundle yet, so it reports off and its screens
 * stay hidden; a port must not show a screen for a feature it does not
 * enforce.
 */
class AccessFeatures
{
    public const ROLES_PERMISSIONS = 'roles_permissions';
    public const TWO_FACTOR = 'two_factor';
    public const SSO = 'sso';

    private readonly bool $rolesPermissions;
    private readonly bool $twoFactor;

    public function __construct(mixed $rolesPermissions = false, mixed $twoFactor = false)
    {
        $this->rolesPermissions = self::toBool($rolesPermissions);
        $this->twoFactor = self::toBool($twoFactor);
    }

    public function rolesPermissions(): bool
    {
        return $this->rolesPermissions;
    }

    public function twoFactor(): bool
    {
        return $this->twoFactor;
    }

    public function sso(): bool
    {
        return false;
    }

    /**
     * Whether the named feature is switched on.
     *
     * @throws \InvalidArgumentException for a name that is not an access feature
     */
    public function enabled(string $feature): bool
    {
        return match ($feature) {
            self::ROLES_PERMISSIONS => $this->rolesPermissions(),
            self::TWO_FACTOR => $this->twoFactor(),
            self::SSO => $this->sso(),
            default => throw new \InvalidArgumentException(sprintf('Unknown Escalated access feature "%s".', $feature)),
        };
    }

    /**
     * The `features` entries shared with every page.
     *
     * @return array{roles_permissions: bool, two_factor: bool, sso: bool}
     */
    public function shared(): array
    {
        return [
            self::ROLES_PERMISSIONS => $this->rolesPermissions(),
            self::TWO_FACTOR => $this->twoFactor(),
            self::SSO => $this->sso(),
        ];
    }

    private static function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \is_scalar($value) && (filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false);
    }
}
