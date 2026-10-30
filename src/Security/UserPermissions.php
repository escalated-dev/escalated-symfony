<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Role;
use Escalated\Symfony\Entity\RoleUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A user's Escalated permissions: the union of their roles' permissions
 * (ADR 2026-10-29-configurable-access-features). Nothing else grants one --
 * not the ESCALATED_ADMIN gate, not a host role.
 *
 * Not cached: roles change between requests in a long-running worker and in
 * tests, and the lookup is one indexed query.
 */
class UserPermissions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessFeatures $features,
        private readonly UserKeyResolver $userKeys,
    ) {
    }

    /**
     * The permission slugs the user holds through their roles.
     *
     * @return list<string>
     */
    public function of(UserInterface $user): array
    {
        $key = $this->userKeys->resolve($user);

        return null === $key ? [] : $this->ofUserKey($key);
    }

    /**
     * @return list<string>
     */
    public function ofUserKey(int|string $userKey): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('DISTINCT p.slug AS slug')
            ->from(RoleUser::class, 'ru')
            ->join('ru.role', 'r')
            ->join('r.permissions', 'p')
            ->where('ru.userId = :user')
            ->setParameter('user', $userKey)
            ->getQuery()
            ->getArrayResult();

        $slugs = array_map(static fn (array $row): string => (string) $row['slug'], $rows);
        sort($slugs);

        return $slugs;
    }

    public function holds(UserInterface $user, string $slug): bool
    {
        return \in_array($slug, $this->of($user), true);
    }

    /**
     * Whether the user may do what the permission covers. While roles &
     * permissions is switched off the gates decide everything, so this is
     * always true; callers have already checked the gate.
     */
    public function allows(UserInterface $user, string $slug): bool
    {
        return !$this->features->rolesPermissions() || $this->holds($user, $slug);
    }

    /**
     * Role slugs per user key, for the users page.
     *
     * @param list<int|string> $userKeys
     *
     * @return array<string, list<string>> keyed by the user key as a string
     */
    public function roleSlugsOf(array $userKeys): array
    {
        if ([] === $userKeys) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('ru.userId AS user_id', 'r.slug AS slug')
            ->from(RoleUser::class, 'ru')
            ->join('ru.role', 'r')
            ->where('ru.userId IN (:users)')
            ->setParameter('users', $userKeys)
            ->orderBy('r.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $slugs = [];
        foreach ($rows as $row) {
            $slugs[(string) $row['user_id']][] = (string) $row['slug'];
        }

        return $slugs;
    }

    /**
     * Give the user the role, keeping the roles they already hold.
     */
    public function assign(int|string $userKey, Role $role): void
    {
        $existing = $this->em->getRepository(RoleUser::class)->findOneBy(['userId' => $userKey, 'role' => $role]);
        if (null === $existing) {
            $this->em->persist(new RoleUser($userKey, $role));
            $this->em->flush();
        }
    }

    /**
     * Replace the user's roles.
     *
     * @param list<Role> $roles
     */
    public function replace(int|string $userKey, array $roles): void
    {
        $wanted = [];
        foreach ($roles as $role) {
            $wanted[(int) $role->getId()] = $role;
        }

        foreach ($this->em->getRepository(RoleUser::class)->findBy(['userId' => $userKey]) as $held) {
            $id = (int) $held->getRole()->getId();
            if (isset($wanted[$id])) {
                unset($wanted[$id]);
            } else {
                $this->em->remove($held);
            }
        }

        foreach ($wanted as $role) {
            $this->em->persist(new RoleUser($userKey, $role));
        }
        $this->em->flush();
    }
}
