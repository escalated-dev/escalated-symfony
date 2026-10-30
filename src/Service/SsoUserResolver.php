<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Escalated\Symfony\Contract\SsoUserProvisionerInterface;
use Escalated\Symfony\Entity\Role;
use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\SsoException;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Security\UserPermissions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Maps an SSO identity to a host user, as escalated-laravel's
 * SsoUserResolver: match by email (case-insensitive), create one only when
 * `escalated.sso.create_users` is on, and replace the user's Escalated roles
 * with the asserted role when roles & permissions and `escalated.sso.sync_roles`
 * are both on. SSO never touches the host's ESCALATED_ADMIN / ESCALATED_AGENT
 * gates: it only signs the user in.
 */
class SsoUserResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessFeatures $features,
        private readonly UserPermissions $permissions,
        private readonly UserKeyResolver $userKeys,
        #[Autowire('%escalated.user_class%')]
        private readonly string $userClass,
        #[Autowire('%escalated.sso.create_users%')]
        private readonly bool $createUsers = false,
        #[Autowire('%escalated.sso.sync_roles%')]
        private readonly bool $syncRoles = false,
        private readonly ?ManagerRegistry $registry = null,
        private readonly ?SsoUserProvisionerInterface $provisioner = null,
        private readonly ?UserPasswordHasherInterface $hasher = null,
    ) {
    }

    /**
     * @param array{email: string, name: string, role: string} $identity
     *
     * @throws SsoException
     */
    public function resolve(array $identity): UserInterface
    {
        $email = $identity['email'];
        $user = $this->findByEmail($email);

        if (null === $user) {
            if (!$this->createUsers) {
                throw new SsoException(sprintf('SSO refused: no user has the email [%s] and escalated.sso.create_users is off.', $email));
            }

            $user = $this->create($email, $identity['name']);
        }

        $this->syncRole($user, $identity['role']);

        return $user;
    }

    private function findByEmail(string $email): ?UserInterface
    {
        $manager = $this->manager();
        if (!$manager instanceof EntityManagerInterface || !$manager->getClassMetadata($this->userClass)->hasField('email')) {
            throw new SsoException(sprintf('SSO refused: the user class [%s] has no mapped email field to match on.', $this->userClass));
        }

        $found = $manager->createQueryBuilder()
            ->select('u')
            ->from($this->userClass, 'u')
            ->where('LOWER(u.email) = :email')
            ->setParameter('email', mb_strtolower($email))
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        if (\count($found) > 1) {
            throw new SsoException(sprintf('SSO refused: more than one user has the email [%s].', $email));
        }

        $user = $found[0] ?? null;

        return $user instanceof UserInterface ? $user : null;
    }

    private function create(string $email, string $name): UserInterface
    {
        $name = '' !== $name ? $name : (string) strstr($email, '@', true);

        if (null !== $this->provisioner) {
            return $this->provisioner->create($email, $name);
        }

        $user = $this->instantiate($email);
        if (method_exists($user, 'setName')) {
            $user->setName($name);
        }
        if (method_exists($user, 'setPassword') && $user instanceof PasswordAuthenticatedUserInterface && null !== $this->hasher) {
            // Unusable: nobody knows it. The user signs in through SSO, or
            // resets it through the host's own flow.
            $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(32))));
        }

        $manager = $this->manager();
        $manager->persist($user);
        $manager->flush();

        return $user;
    }

    private function instantiate(string $email): UserInterface
    {
        $class = new \ReflectionClass($this->userClass);
        $constructor = $class->getConstructor();
        $required = null === $constructor
            ? []
            : array_values(array_filter($constructor->getParameters(), static fn (\ReflectionParameter $p): bool => !$p->isOptional()));

        if ([] === $required) {
            $user = $class->newInstance();
            if (!method_exists($user, 'setEmail')) {
                throw new SsoException(sprintf('SSO refused: cannot create a [%s] (no setEmail()); set escalated.sso.user_provisioner.', $this->userClass));
            }
            $user->setEmail($email);
        } elseif (1 === \count($required) && 'email' === $required[0]->getName()) {
            $user = $class->newInstance($email);
        } else {
            throw new SsoException(sprintf('SSO refused: cannot create a [%s] from an email; set escalated.sso.user_provisioner.', $this->userClass));
        }

        if (!$user instanceof UserInterface) {
            throw new SsoException(sprintf('SSO refused: [%s] is not a security user.', $this->userClass));
        }

        return $user;
    }

    private function syncRole(UserInterface $user, string $roleSlug): void
    {
        if ('' === $roleSlug || !$this->features->rolesPermissions() || !$this->syncRoles) {
            return;
        }

        $role = $this->em->getRepository(Role::class)->findOneBy(['slug' => $roleSlug]);
        $userKey = $this->userKeys->resolve($user);
        if (!$role instanceof Role || null === $userKey) {
            return;
        }

        $this->permissions->replace($userKey, [$role]);
    }

    private function manager(): ObjectManager
    {
        return $this->registry?->getManagerForClass($this->userClass) ?? $this->em;
    }
}
