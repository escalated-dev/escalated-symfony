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
        private readonly ?\Doctrine\Persistence\ManagerRegistry $registry = null,
    ) {
    }

    public function resolve(UserInterface $user): int|string|null
    {
        if ($user instanceof ApiTokenUser) {
            return self::normalize($user->getUserIdentifier());
        }

        $manager = $this->registry?->getManagerForClass($user::class) ?? $this->em;
        $factory = $manager->getMetadataFactory();
        if (!$factory->hasMetadataFor($user::class) && $factory->isTransient($user::class)) {
            return null;
        }

        $idValues = $manager->getClassMetadata($user::class)->getIdentifierValues($user);
        $id = reset($idValues);

        if (false === $id || null === $id) {
            return null;
        }

        return self::normalize($id);
    }

    /**
     * Shape a stored or submitted user id the way the host's key type does:
     * numeric ids become ints for int/bigint keys, uuid/string keys stay strings.
     */
    public static function normalize(mixed $id): int|string|null
    {
        if (is_int($id)) {
            return $id;
        }

        if (is_string($id)) {
            if ('' === $id) {
                return null;
            }

            $type = strtolower((string) ($_ENV['ESCALATED_USER_KEY_TYPE'] ?? getenv('ESCALATED_USER_KEY_TYPE') ?: 'int'));

            return !in_array($type, ['uuid', 'string', 'varchar'], true) && is_numeric($id) ? (int) $id : $id;
        }

        return is_numeric($id) ? (int) $id : (string) $id;
    }
}
