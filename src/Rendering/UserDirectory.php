<?php

declare(strict_types=1);

namespace Escalated\Symfony\Rendering;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Security\UserKeyResolver;

/**
 * Host users as the shared pages show them: `{id, name, email}`.
 *
 * The bundle stores only user keys (requester_id, assigned_to, author ids), so
 * a page that shows a name looks the key up in the host's user entity
 * (`escalated.user_class`). A host user without a `getName()` is shown by its
 * email, then its security identifier. Nothing else is read from the entity,
 * so no credential it holds can reach a page.
 */
class UserDirectory
{
    /** @var array<string, array{id: int|string, name: string, email: string}|null> */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $userClass,
        private readonly ?ManagerRegistry $registry = null,
    ) {
    }

    /**
     * The staff a ticket can go to: every user with an agent profile, as the
     * ESCALATED_AGENT voter decides who is an agent.
     *
     * @return list<array{id: int|string, name: string, email: string}>
     */
    public function agents(): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('ap.userId')
            ->from(AgentProfile::class, 'ap')
            ->orderBy('ap.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        $agents = [];
        foreach ($this->findMany($ids) as $user) {
            $agents[] = $user;
        }

        usort($agents, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $agents;
    }

    /**
     * @return array{id: int|string, name: string, email: string}|null
     */
    public function find(int|string|null $id): ?array
    {
        if (null === $id || '' === $id) {
            return null;
        }

        return $this->findMany([$id])[(string) $id] ?? null;
    }

    public function name(int|string|null $id): ?string
    {
        return $this->find($id)['name'] ?? null;
    }

    /**
     * @param iterable<int|string|null> $ids
     *
     * @return array<string, array{id: int|string, name: string, email: string}>
     */
    public function findMany(iterable $ids): array
    {
        $ids = \is_array($ids) ? $ids : iterator_to_array($ids, false);
        $wanted = [];
        foreach ($ids as $id) {
            $id = UserKeyResolver::normalize($id);
            if (null !== $id && !\array_key_exists((string) $id, $this->cache)) {
                $wanted[(string) $id] = $id;
            }
        }

        if ([] !== $wanted) {
            foreach ($wanted as $key => $id) {
                $this->cache[$key] = null;
            }
            $this->load(array_values($wanted));
        }

        $found = [];
        foreach ($ids as $id) {
            $key = (string) UserKeyResolver::normalize($id);
            if (isset($this->cache[$key])) {
                $found[$key] = $this->cache[$key];
            }
        }

        return $found;
    }

    /**
     * @param list<int|string> $ids
     */
    private function load(array $ids): void
    {
        $manager = $this->manager();
        if (null === $manager) {
            return;
        }

        try {
            $metadata = $manager->getClassMetadata($this->userClass);
            $idField = $metadata->getIdentifierFieldNames()[0] ?? 'id';
            $users = $manager->getRepository($this->userClass)->findBy([$idField => $ids]);
        } catch (\Throwable) {
            // A host whose user entity is not mapped (or not yet migrated)
            // shows keys without names rather than failing the page.
            return;
        }

        foreach ($users as $user) {
            $values = $metadata->getIdentifierValues($user);
            $id = UserKeyResolver::normalize(reset($values));
            if (null === $id) {
                continue;
            }
            $email = $this->read($user, ['getEmail']) ?? '';
            $this->cache[(string) $id] = [
                'id' => $id,
                'name' => $this->read($user, ['getName', 'getFullName', 'getDisplayName']) ?? ('' !== $email ? $email : ($this->read($user, ['getUserIdentifier']) ?? (string) $id)),
                'email' => '' !== $email ? $email : ($this->read($user, ['getUserIdentifier']) ?? ''),
            ];
        }
    }

    /**
     * The id of the host user with this email (the `email` field of the user
     * entity), or null when there is none or the user entity is not mapped.
     */
    public function findIdByEmail(string $email): int|string|null
    {
        $manager = $this->manager();
        if (null === $manager || '' === trim($email)) {
            return null;
        }

        try {
            $metadata = $manager->getClassMetadata($this->userClass);
            if (!$metadata->hasField('email')) {
                return null;
            }
            $user = $manager->getRepository($this->userClass)->findOneBy(['email' => trim($email)]);
        } catch (\Throwable) {
            return null;
        }

        if (null === $user) {
            return null;
        }
        $values = $metadata->getIdentifierValues($user);

        return UserKeyResolver::normalize(reset($values));
    }

    /**
     * @param list<string> $getters
     */
    private function read(object $user, array $getters): ?string
    {
        foreach ($getters as $getter) {
            if (method_exists($user, $getter)) {
                $value = $user->{$getter}();
                if (\is_scalar($value) && '' !== (string) $value) {
                    return (string) $value;
                }
            }
        }

        return null;
    }

    private function manager(): ?ObjectManager
    {
        if (!class_exists($this->userClass)) {
            return null;
        }

        return $this->registry?->getManagerForClass($this->userClass) ?? $this->em;
    }
}
