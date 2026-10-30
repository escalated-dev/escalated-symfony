<?php

declare(strict_types=1);

namespace Escalated\Symfony\Contract;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Creates a host user for an SSO identity whose email no user has, when
 * `escalated.sso.create_users` is on. Register a service implementing it and
 * name it in `escalated.sso.user_provisioner` when the default (build
 * `user_class` from the email, name and an unusable random password) does
 * not fit the host's user entity. The returned user must be persisted and
 * loadable by the firewall's user provider.
 */
interface SsoUserProvisionerInterface
{
    public function create(string $email, string $name): UserInterface;
}
