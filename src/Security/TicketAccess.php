<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Contract\TicketAccessUserResolverInterface;
use Escalated\Symfony\Contract\TicketAccountResolverInterface;
use Escalated\Symfony\Doctrine\TicketVisibilityFilter;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Ticket;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

/** Request-local access context. No host-table joins or process-global actor state. */
final class TicketAccess implements ResetInterface
{
    private ?UserInterface $actor = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserKeyResolver $keys,
        private readonly ManagerRegistry $registry,
        private readonly string $visibility = 'all',
        private readonly bool $accountsEnabled = false,
        private readonly bool $scopeAccounts = false,
        private readonly ?TicketAccountResolverInterface $accountResolver = null,
        private readonly ?TicketAccessUserResolverInterface $userResolver = null,
        private readonly string $userClass = 'App\\Entity\\User',
    ) {
    }

    public function accountsEnabled(): bool
    {
        return $this->accountsEnabled;
    }

    public function restricted(): bool
    {
        return 'department' === $this->visibility || ($this->accountsEnabled && $this->scopeAccounts);
    }

    public function actor(): ?UserInterface
    {
        return $this->actor;
    }

    public function reset(): void
    {
        $this->setActor(null);
    }

    public function setActor(?UserInterface $user): void
    {
        $this->actor = $user;
        $filters = $this->em->getFilters();
        if ($filters->isEnabled(TicketVisibilityFilter::NAME)) {
            $filters->disable(TicketVisibilityFilter::NAME);
        }
        if (!$this->restricted() || null === $user) {
            return;
        }
        $id = $this->keys->resolve($user);
        // Token abilities are permission ceilings, not proof that the owner is an admin.
        $identity = $user instanceof ApiTokenUser && null !== $id ? $this->loadUser($id) : $user;
        if ($user instanceof ApiTokenUser && null !== $identity && (string) $this->keys->resolve($identity) !== (string) $id) {
            $identity = null;
        }
        $admin = null !== $identity && in_array('ROLE_ESCALATED_ADMIN', $identity->getRoles(), true);
        if ($admin && (!$user instanceof ApiTokenUser || in_array('ROLE_ESCALATED_ADMIN', $user->getRoles(), true))) {
            return;
        }
        $agent = $user instanceof ApiTokenUser || in_array('ROLE_ESCALATED_AGENT', $user->getRoles(), true) || (null !== $id && null !== $this->em->getRepository(AgentProfile::class)->findOneBy(['userId' => $id]));
        if (!$agent) {
            return;
        } // Customer ownership and guest-token checks remain in their controllers.
        $this->em->getConfiguration()->addFilter(TicketVisibilityFilter::NAME, TicketVisibilityFilter::class);
        $filter = $filters->enable(TicketVisibilityFilter::NAME);
        $filter->configure($this->em);
        if (null === $id) {
            $filter->setParameter('deny', 1);

            return;
        }
        if ('department' === $this->visibility) {
            $filter->setParameter('agent_id', $id);
        }
        if ($this->accountsEnabled && $this->scopeAccounts) {
            $account = $this->resolveAccount('agent', $this->userClass, $id);
            if (null === $account) {
                $filter->setParameter('deny', 1);
            } else {
                $filter->setParameter('account_type', $account['type']);
                $filter->setParameter('account_id', $account['id']);
            }
        }
    }

    public function runAs(?UserInterface $user, callable $work): mixed
    {
        $previous = $this->actor;
        try {
            $this->setActor($user);

            return $work();
        } finally {
            $this->setActor($previous);
        }
    }

    public function assertVisible(Ticket $ticket): void
    {
        if (!$this->visibleEntity($ticket)) {
            throw new NotFoundHttpException('Ticket not found.');
        }
    }

    public function predicate(string $class, string $alias): string
    {
        if (!$this->em->getFilters()->isEnabled(TicketVisibilityFilter::NAME)) {
            return '1 = 1';
        }

        return $this->em->getFilters()->getFilter(TicketVisibilityFilter::NAME)->constraint($this->em->getClassMetadata($class), $alias) ?: '1 = 1';
    }

    public function visibleEntity(object $entity): bool
    {
        if (!$this->em->getFilters()->isEnabled(TicketVisibilityFilter::NAME)) {
            return true;
        }
        $meta = $this->em->getClassMetadata($entity::class);
        $predicate = $this->predicate($meta->name, 'v');
        $ids = $meta->getIdentifierValues($entity);
        if ('1 = 1' === $predicate || [] === $ids) {
            return true;
        }
        $id = reset($ids);
        $table = $this->em->getConfiguration()->getQuoteStrategy()->getTableName($meta, $this->em->getConnection()->getDatabasePlatform());

        return false !== $this->em->getConnection()->fetchOne("SELECT 1 FROM $table v WHERE v.id = ? AND ($predicate)", [$id]);
    }

    public function resolveAccount(string $kind, ?string $type, int|string|null $id): ?array
    {
        if (!$this->accountsEnabled) {
            return null;
        }

        return self::accountPair($this->accountResolver?->resolve($kind, $type, $id));
    }

    public static function accountPair(mixed $value): ?array
    {
        if (!is_array($value) || !is_string($value['type'] ?? null) || (!is_string($value['id'] ?? null) && !is_int($value['id'] ?? null))) {
            return null;
        }
        $type = $value['type'];
        $id = (string) $value['id'];

        return '' === $type || '' === $id || strlen($type) > 191 || strlen($id) > 191 ? null : ['type' => $type, 'id' => $id];
    }

    public function resolveRequester(Ticket $ticket): void
    {
        $this->applyAccount($ticket, $this->resolveAccount('requester', $ticket->getRequesterClass(), $ticket->getRequesterId()));
    }

    public function resolveSubject(Ticket $ticket, string $type, int|string $id): void
    {
        $this->applyAccount($ticket, $this->resolveAccount('subject', $type, $id));
    }

    private function applyAccount(Ticket $ticket, ?array $account): void
    {
        if (null !== $account && null === $ticket->getAccountType() && null === $ticket->getAccountId()) {
            if (null !== $ticket->getId()) {
                $meta = $this->em->getClassMetadata(Ticket::class);
                $conn = $this->em->getConnection();
                $table = $this->em->getConfiguration()->getQuoteStrategy()->getTableName($meta, $conn->getDatabasePlatform());
                $conn->executeStatement("UPDATE $table SET account_type = ?, account_id = ? WHERE id = ? AND account_type IS NULL AND account_id IS NULL", [$account['type'], $account['id'], $ticket->getId()]);
                $row = $conn->fetchAssociative("SELECT account_type, account_id FROM $table WHERE id = ?", [$ticket->getId()]);
                if (false !== $row) {
                    $ticket->setAccount($row['account_type'], $row['account_id']);
                }

                return;
            }
            $ticket->setAccount($account['type'], $account['id']);
        }
    }

    public function canReceive(Ticket $ticket, int|string $id): bool
    {
        if (!$this->restricted()) {
            return true;
        }
        $user = $this->loadUser($id);
        if (null === $user || (string) $this->keys->resolve($user) !== (string) $id) {
            return false;
        }
        if (in_array('ROLE_ESCALATED_ADMIN', $user->getRoles(), true)) {
            return true;
        }
        $agent = null !== $this->em->getRepository(AgentProfile::class)->findOneBy(['userId' => $id]);
        if (!$agent) {
            return (string) $ticket->getRequesterId() === (string) $id && is_a($user, $ticket->getRequesterClass() ?? $this->userClass);
        }

        return $this->runAs($user, fn () => $this->visibleEntity($ticket));
    }

    public function canAssign(Ticket $ticket, int|string $id): bool
    {
        if (!$this->restricted()) {
            return true;
        }
        $user = $this->loadUser($id);
        if (null === $user || (string) $this->keys->resolve($user) !== (string) $id) {
            return false;
        }
        if (in_array('ROLE_ESCALATED_ADMIN', $user->getRoles(), true)) {
            return true;
        }
        if (null === $this->em->getRepository(AgentProfile::class)->findOneBy(['userId' => $id])) {
            return false;
        }
        if ('department' === $this->visibility) {
            $department = $ticket->getDepartment()?->getId();
            $conn = $this->em->getConnection();
            $meta = $this->em->getClassMetadata(\Escalated\Symfony\Entity\DepartmentAgent::class);
            $table = $this->em->getConfiguration()->getQuoteStrategy()->getTableName($meta, $conn->getDatabasePlatform());
            $identity = \Escalated\Symfony\Doctrine\ExactIdentity::equals($conn->getDatabasePlatform(), 'agent_id', '?');
            if (null === $department || false === $conn->fetchOne("SELECT 1 FROM $table WHERE department_id = ? AND ($identity)", [$department, $id])) {
                return false;
            }
        }
        if ($this->accountsEnabled && $this->scopeAccounts) {
            $account = $this->resolveAccount('agent', $this->userClass, $id);
            if (null === $account || $account['type'] !== $ticket->getAccountType() || $account['id'] !== $ticket->getAccountId()) {
                return false;
            }
        }

        return true;
    }

    public function assertAssignable(Ticket $ticket, int|string $id): void
    {
        if (!$this->canAssign($ticket, $id)) {
            throw new \InvalidArgumentException('Agent cannot access this ticket.');
        }
    }

    public function recipients(Ticket $ticket, iterable $ids, int|string|null $exclude = null): array
    {
        return array_values(array_filter(\Escalated\Symfony\Service\FollowerRecipients::resolve($ids, $exclude), fn ($id) => $this->canReceive($ticket, $id)));
    }

    private function loadUser(int|string $id): ?UserInterface
    {
        if (null !== $this->userResolver) {
            return $this->userResolver->resolve($id);
        }
        if (!class_exists($this->userClass)) {
            return null;
        }
        $manager = $this->registry->getManagerForClass($this->userClass);
        if (null === $manager) {
            return null;
        }
        // Scalar hydration is not used: host identities may implement custom role logic.
        $metadata = $manager->getClassMetadata($this->userClass);
        $user = $manager->getRepository($this->userClass)->findOneBy([$metadata->getSingleIdentifierFieldName() => $id]);
        if ($user instanceof UserInterface) {
            $manager->refresh($user);

            return $user;
        }

        return null;
    }
}
