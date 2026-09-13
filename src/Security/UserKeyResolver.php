<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves the host user's primary key for the current security user -- the
 * value Escalated stores in requester_id, assigned_to, agent_profiles.user_id
 * and so on.
 *
 * getUserIdentifier() is not that value: for a session user it is whatever
 * the provider loads by (typically the email). For an API token user it is the
 * token owner's id, which is the key.
 */
class UserKeyResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function resolve(UserInterface $user): int|string|null
    {
        if ($user instanceof ApiTokenUser) {
            return self::normalize($user->getUserIdentifier());
        }

        $factory = $this->em->getMetadataFactory();
        if (!$factory->hasMetadataFor($user::class) && $factory->isTransient($user::class)) {
            return null;
        }

        $idValues = $this->em->getClassMetadata($user::class)->getIdentifierValues($user);
        $id = reset($idValues);

        if (false === $id || null === $id) {
            return null;
        }

        return self::normalize($id);
    }

    private static function normalize(mixed $id): int|string|null
    {
        if (is_int($id)) {
            return $id;
        }

        if (is_string($id)) {
            if ('' === $id) {
                return null;
            }

            return is_numeric($id) ? (int) $id : $id;
        }

        return is_numeric($id) ? (int) $id : (string) $id;
    }
}
