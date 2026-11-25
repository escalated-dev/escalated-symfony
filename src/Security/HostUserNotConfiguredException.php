<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

/**
 * The host's user is not set up the way Escalated needs it.
 *
 * Escalated stores host users by primary key (requester, assignee, reply
 * author, agent profile), so the user the firewall signs in must be a
 * Doctrine-mapped entity implementing Symfony's UserInterface, and
 * `escalated.user_class` must name that entity. Without that a staff action
 * has no user key to record; this says so instead of failing further down
 * with a type or SQL error.
 */
final class HostUserNotConfiguredException extends \LogicException
{
    private const CONTRACT = 'Escalated needs the user your firewall signs in to be a Doctrine-mapped entity that implements '
        .'Symfony\Component\Security\Core\User\UserInterface, and escalated.user_class (config/packages/escalated.yaml) '
        .'set to that entity class. Run "php bin/console escalated:doctor" to check the setup.';

    public static function notAnEntity(string $class): self
    {
        return new self(sprintf(
            'Escalated cannot record who is acting: the signed-in user is a %s, which is not a Doctrine entity, so it has no '
            .'primary key to store. Point the firewall\'s user provider at your Doctrine user entity. %s',
            $class,
            self::CONTRACT,
        ));
    }

    public static function noKey(string $class): self
    {
        return new self(sprintf(
            'Escalated cannot record who is acting: the signed-in %s has no primary key (it was never persisted). %s',
            $class,
            self::CONTRACT,
        ));
    }

    public static function userClass(string $class, string $problem): self
    {
        return new self(sprintf('escalated.user_class is "%s", which %s. %s', $class, $problem, self::CONTRACT));
    }
}
