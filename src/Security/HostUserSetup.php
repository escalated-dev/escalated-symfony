<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Checks `escalated.user_class` against what Escalated needs from the host
 * user: a class that exists, implements UserInterface and is mapped by
 * Doctrine (its primary key is what the bundle stores).
 */
class HostUserSetup
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%escalated.user_class%')]
        private readonly string $userClass,
        private readonly ?ManagerRegistry $registry = null,
    ) {
    }

    public function userClass(): string
    {
        return $this->userClass;
    }

    /** What is wrong with `escalated.user_class`, or null when nothing is. */
    public function userClassProblem(): ?string
    {
        if ('' === $this->userClass || !class_exists($this->userClass)) {
            return 'does not exist';
        }
        if (!is_subclass_of($this->userClass, UserInterface::class)) {
            return 'does not implement '.UserInterface::class;
        }

        try {
            $manager = $this->registry?->getManagerForClass($this->userClass) ?? $this->em;
            $factory = $manager->getMetadataFactory();
            if (!$factory->hasMetadataFor($this->userClass) && $factory->isTransient($this->userClass)) {
                return 'is not a Doctrine entity';
            }
            $manager->getClassMetadata($this->userClass);
        } catch (\Throwable) {
            return 'is not a Doctrine entity';
        }

        return null;
    }

    /**
     * @throws HostUserNotConfiguredException
     */
    public function assertUserClass(): void
    {
        $problem = $this->userClassProblem();
        if (null !== $problem) {
            throw HostUserNotConfiguredException::userClass($this->userClass, $problem);
        }
    }
}
