<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

/**
 * Hides a controller (class) or action (method) while an access feature is
 * switched off: the request answers 404, as if the route did not exist.
 * Enforced by {@see \Escalated\Symfony\EventSubscriber\FeatureGateSubscriber}.
 *
 * The routes stay registered (a cached router cannot see the switch), the
 * same way escalated-laravel's EnsureFeatureEnabled middleware hides them.
 *
 *     #[RequiresFeature(AccessFeatures::ROLES_PERMISSIONS)]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RequiresFeature
{
    public function __construct(public readonly string $feature)
    {
    }
}
