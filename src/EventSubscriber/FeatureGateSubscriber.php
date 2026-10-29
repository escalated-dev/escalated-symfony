<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventSubscriber;

use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\RequiresFeature;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers 404 for a controller marked {@see RequiresFeature} while that
 * access feature is switched off (ADR 2026-10-29-configurable-access-features).
 *
 * Runs before the route-permission check and the shared-props subscriber, so
 * a hidden screen is a 404 for everyone, whatever their permissions.
 */
final class FeatureGateSubscriber implements EventSubscriberInterface
{
    public const PRIORITY = 64;

    public function __construct(private readonly AccessFeatures $features)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', self::PRIORITY]];
    }

    public function onController(ControllerEvent $event): void
    {
        foreach ($event->getAttributes(RequiresFeature::class) as $requirement) {
            \assert($requirement instanceof RequiresFeature);
            if (!$this->features->enabled($requirement->feature)) {
                throw new NotFoundHttpException('Not Found');
            }
        }
    }
}
