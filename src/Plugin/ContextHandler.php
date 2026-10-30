<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Broadcasting\BroadcastableEvent;
use Escalated\Symfony\Broadcasting\BroadcasterInterface;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Rendering\UserDirectory;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\TicketService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The SDK's `ctx` for one plugin: the `ctx.*` methods an SDK plugin calls over
 * the bridge, and a native plugin through {@see PluginContext}.
 *
 * Every method is scoped to the plugin being served, which the caller passes;
 * a `plugin` parameter in `$params` is never read. Writes act as the signed-in
 * user of the current request, or as no user outside one; a plugin never
 * names the actor or the requester. Ticket and reply filters accept only the
 * contract's keys, mapped to fields here, so a filter key never becomes SQL.
 */
class ContextHandler
{
    /** Most rows ctx.tickets.query and ctx.replies.query return. */
    public const QUERY_LIMIT = 500;

    private const TICKET_FILTERS = [
        'id' => 't.id',
        'reference' => 't.reference',
        'status' => 't.status',
        'priority' => 't.priority',
        'channel' => 't.channel',
        'ticket_type' => 't.ticketType',
        'department_id' => 'IDENTITY(t.department)',
        'requester_id' => 't.requesterId',
        'contact_id' => 'IDENTITY(t.contact)',
        'assigned_to' => 't.assignedTo',
    ];

    private const REPLY_FILTERS = [
        'ticket_id' => 'IDENTITY(r.ticket)',
        'is_internal' => 'r.isInternalNote',
        'type' => 'r.type',
    ];

    private const PRIORITIES = [
        Ticket::PRIORITY_LOW, Ticket::PRIORITY_MEDIUM, Ticket::PRIORITY_HIGH, Ticket::PRIORITY_URGENT, Ticket::PRIORITY_CRITICAL,
    ];

    private ?PluginRegistry $registry = null;

    /** While above 0, plugin calls act for no user (cron). */
    private int $noUser = 0;

    public function __construct(
        private readonly PluginStore $store,
        private readonly PluginPayloads $payloads,
        private readonly EntityManagerInterface $em,
        private readonly TicketService $tickets,
        private readonly Security $security,
        private readonly UserKeyResolver $userKeys,
        private readonly UserDirectory $users,
        private readonly BroadcasterInterface $broadcaster,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @internal set by the registry, for ctx.emit
     */
    public function setRegistry(PluginRegistry $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * @param string               $plugin the name of the plugin being served
     * @param string               $method e.g. `ctx.store.query`
     * @param array<string, mixed> $params
     */
    public function handle(string $plugin, string $method, array $params): mixed
    {
        return match ($method) {
            'ctx.config.all' => $this->store->configAll($plugin),
            'ctx.config.get' => $this->store->configGet($plugin, $this->string($params, 'key')),
            'ctx.config.set' => $this->configSet($plugin, $params),

            'ctx.store.get' => $this->store->get($plugin, $this->string($params, 'collection'), $this->string($params, 'key')),
            'ctx.store.set' => $this->store->set($plugin, $this->string($params, 'collection'), $this->string($params, 'key'), $params['value'] ?? null),
            'ctx.store.query' => $this->store->query($plugin, $this->string($params, 'collection'), $this->array($params, 'filter'), $this->array($params, 'options')),
            'ctx.store.insert' => $this->store->insert($plugin, $this->string($params, 'collection'), $this->array($params, 'data', required: true)),
            'ctx.store.update' => $this->store->update($plugin, $this->string($params, 'collection'), $this->string($params, 'key'), $this->array($params, 'data', required: true)),
            'ctx.store.delete' => $this->store->delete($plugin, $this->string($params, 'collection'), $this->string($params, 'key')),

            'ctx.tickets.find' => $this->ticketsFind($params),
            'ctx.tickets.query' => $this->ticketsQuery($this->array($params, 'filter')),
            'ctx.tickets.create' => $this->ticketsCreate($this->array($params, 'data', required: true)),
            'ctx.tickets.update' => $this->ticketsUpdate($params['id'] ?? null, $this->array($params, 'data', required: true)),

            'ctx.replies.find' => $this->repliesFind($params),
            'ctx.replies.query' => $this->repliesQuery($this->array($params, 'filter')),
            'ctx.replies.create' => $this->repliesCreate($this->array($params, 'data', required: true)),

            'ctx.contacts.find' => $this->contactsFind($params),
            'ctx.contacts.findByEmail' => $this->contactsFindByEmail($this->string($params, 'email')),
            'ctx.contacts.create' => $this->contactsCreate($this->array($params, 'data', required: true)),

            'ctx.tags.all' => array_map(fn (Tag $tag): array => $this->payloads->tagWithColor($tag), $this->em->getRepository(Tag::class)->findBy([], ['name' => 'ASC'])),
            'ctx.tags.create' => $this->tagsCreate($this->array($params, 'data', required: true)),

            'ctx.departments.all' => array_map(fn (Department $d): array => $this->payloads->department($d), $this->em->getRepository(Department::class)->findBy([], ['name' => 'ASC'])),
            'ctx.departments.find' => $this->departmentsFind($params),

            'ctx.agents.all' => $this->users->agents(),
            'ctx.agents.find' => $this->agentsFind($params),

            'ctx.broadcast.toChannel' => $this->broadcast($plugin, 'plugin.'.$this->string($params, 'channel'), $params),
            'ctx.broadcast.toUser' => $this->broadcast($plugin, 'private-user.'.$this->scalar($params, 'userId'), $params),
            'ctx.broadcast.toTicket' => $this->broadcast($plugin, 'private-ticket.'.$this->scalar($params, 'ticketId'), $params),

            'ctx.log' => $this->log($plugin, $params),
            'ctx.emit' => $this->emit($plugin, $params),
            'ctx.currentUser' => $this->currentUser(),

            default => throw new \InvalidArgumentException(sprintf('Unknown ctx method: %s', $method)),
        };
    }

    /**
     * Runs `$work` with plugin calls acting for no user, whoever is signed in
     * (cron schedules run as no user).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function asNoUser(callable $work): mixed
    {
        ++$this->noUser;
        try {
            return $work();
        } finally {
            --$this->noUser;
        }
    }

    /**
     * The id of the signed-in user of the request being served, or null.
     */
    public function actorId(): int|string|null
    {
        if ($this->noUser > 0) {
            return null;
        }
        $user = $this->security->getUser();

        return $user instanceof UserInterface ? $this->userKeys->resolve($user) : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function configSet(string $plugin, array $params): null
    {
        $this->store->configSet($plugin, $this->array($params, 'data', required: true));

        return null;
    }

    // Tickets

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function ticketsFind(array $params): ?array
    {
        $ticket = $this->ticket($params['id'] ?? null);

        return null === $ticket ? null : $this->payloads->ticket($ticket);
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return list<array<string, mixed>>
     */
    private function ticketsQuery(array $filter): array
    {
        $query = $this->em->createQueryBuilder()->select('t')->from(Ticket::class, 't')
            ->where('t.deletedAt IS NULL')
            ->orderBy('t.id', 'ASC')
            ->setMaxResults(self::QUERY_LIMIT);
        $this->applyFilter($query, $filter, self::TICKET_FILTERS, 'ctx.tickets.query');

        return array_map(fn (Ticket $ticket): array => $this->payloads->ticket($ticket), $query->getQuery()->getResult());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function ticketsCreate(array $data): array
    {
        $subject = $this->text($data['subject'] ?? $data['title'] ?? null);
        if (null === $subject) {
            throw new \InvalidArgumentException('ctx.tickets.create requires a subject.');
        }

        $input = [
            'subject' => $subject,
            'description' => $this->text($data['description'] ?? $data['body'] ?? null),
            'priority' => $this->priority($data['priority'] ?? null) ?? Ticket::PRIORITY_MEDIUM,
            'ticket_type' => $this->text($data['ticket_type'] ?? null),
            'channel' => $this->text($data['channel'] ?? null) ?? Ticket::CHANNEL_API,
        ];
        if (\array_key_exists('metadata', $data)) {
            $input['metadata'] = \is_array($data['metadata']) ? $data['metadata'] : null;
        }
        if (isset($data['department_id'])) {
            $input['department_id'] = $this->department($data['department_id'])->getId();
        }

        // The requester is a contact, never a user id the plugin names.
        $contact = null;
        if (isset($data['contact_id'])) {
            $contact = $this->em->find(Contact::class, $this->int($data['contact_id'], 'contact_id'))
                ?? throw new \InvalidArgumentException('ctx.tickets.create: no such contact.');
        }
        $email = $this->text($data['requester_email'] ?? null);
        if (null !== $contact) {
            $input['guest_email'] = $contact->getEmail();
            $input['guest_name'] = $contact->getName() ?? $contact->getEmail();
        } elseif (null !== $email) {
            if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('ctx.tickets.create: requester_email is not an email address.');
            }
            $input['guest_email'] = $email;
            $input['guest_name'] = $this->text($data['requester_name'] ?? null) ?? $email;
        }

        $ticket = $this->tickets->create($input);
        $status = $this->text($data['status'] ?? null);
        if (null !== $status && $status !== $ticket->getStatus()) {
            $this->tickets->changeStatus($ticket, $status, $this->actorId());
        }

        return $this->payloads->ticket($ticket);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function ticketsUpdate(mixed $id, array $data): array
    {
        $ticket = $this->ticket($id) ?? throw new \InvalidArgumentException('ctx.tickets.update: no such ticket.');
        $actor = $this->actorId();

        $changes = [];
        if (null !== ($subject = $this->text($data['subject'] ?? $data['title'] ?? null))) {
            $changes['subject'] = $subject;
        }
        if (null !== ($description = $this->text($data['description'] ?? $data['body'] ?? null))) {
            $changes['description'] = $description;
        }
        if (\array_key_exists('ticket_type', $data)) {
            $changes['ticket_type'] = $this->text($data['ticket_type']);
        }
        if (\array_key_exists('metadata', $data)) {
            $changes['metadata'] = \is_array($data['metadata']) ? $data['metadata'] : null;
        }
        if ([] !== $changes) {
            $this->tickets->update($ticket, $changes);
        }
        if (null !== ($priority = $this->priority($data['priority'] ?? null))) {
            $this->tickets->changePriority($ticket, $priority, $actor);
        }
        if (\array_key_exists('department_id', $data)) {
            $departmentId = null === $data['department_id'] ? null : $this->department($data['department_id'])->getId();
            $this->tickets->changeDepartment($ticket, $departmentId, $actor);
        }
        $status = $this->text($data['status'] ?? null);
        if (null !== $status && $status !== $ticket->getStatus()) {
            $this->tickets->changeStatus($ticket, $status, $actor);
        }

        return $this->payloads->ticket($ticket);
    }

    private function ticket(mixed $id): ?Ticket
    {
        if (!is_numeric($id)) {
            return null;
        }
        $ticket = $this->em->find(Ticket::class, (int) $id);

        return null !== $ticket && null === $ticket->getDeletedAt() ? $ticket : null;
    }

    // Replies

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function repliesFind(array $params): ?array
    {
        $id = $params['id'] ?? null;
        $reply = is_numeric($id) ? $this->em->find(Reply::class, (int) $id) : null;

        return null === $reply || null !== $reply->getDeletedAt() ? null : $this->payloads->reply($reply);
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return list<array<string, mixed>>
     */
    private function repliesQuery(array $filter): array
    {
        $query = $this->em->createQueryBuilder()->select('r')->from(Reply::class, 'r')
            ->where('r.deletedAt IS NULL')
            ->orderBy('r.id', 'ASC')
            ->setMaxResults(self::QUERY_LIMIT);
        $this->applyFilter($query, $filter, self::REPLY_FILTERS, 'ctx.replies.query');

        return array_map(fn (Reply $reply): array => $this->payloads->reply($reply), $query->getQuery()->getResult());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function repliesCreate(array $data): array
    {
        $ticket = $this->ticket($data['ticket_id'] ?? null) ?? throw new \InvalidArgumentException('ctx.replies.create requires an existing ticket_id.');
        $body = $this->text($data['body'] ?? null) ?? throw new \InvalidArgumentException('ctx.replies.create requires a body.');

        $reply = $this->tickets->addReply($ticket, $this->actorId(), $body, true === ($data['is_internal'] ?? false));

        return $this->payloads->reply($reply);
    }

    // Contacts

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function contactsFind(array $params): ?array
    {
        $id = $params['id'] ?? null;
        $contact = is_numeric($id) ? $this->em->find(Contact::class, (int) $id) : null;

        return null === $contact ? null : $this->payloads->contact($contact);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contactsFindByEmail(string $email): ?array
    {
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => Contact::normalizeEmail($email)]);

        return null === $contact ? null : $this->payloads->contact($contact);
    }

    /**
     * Finds the contact with this email, or creates it.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function contactsCreate(array $data): array
    {
        $email = $this->text($data['email'] ?? null);
        if (null === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('ctx.contacts.create requires an email address.');
        }
        $name = $this->text($data['name'] ?? null);

        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => Contact::normalizeEmail($email)]);
        if (null === $contact) {
            $contact = (new Contact())->setEmail($email);
            $this->em->persist($contact);
        }
        if (null !== $name && null === $contact->getName()) {
            $contact->setName($name);
        }
        $this->em->flush();

        return $this->payloads->contact($contact);
    }

    // Tags, departments, agents

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function tagsCreate(array $data): array
    {
        $name = $this->text($data['name'] ?? null) ?? throw new \InvalidArgumentException('ctx.tags.create requires a name.');
        $slug = strtolower((string) (new AsciiSlugger())->slug($name));
        $tag = $this->em->getRepository(Tag::class)->findOneBy(['slug' => $slug]);
        if (null === $tag) {
            $tag = (new Tag())->setName($name)->setSlug($slug);
            if (null !== ($color = $this->text($data['color'] ?? null))) {
                $tag->setColor($color);
            }
            $this->em->persist($tag);
            $this->em->flush();
        }

        return $this->payloads->tagWithColor($tag);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function departmentsFind(array $params): ?array
    {
        $id = $params['id'] ?? null;
        $department = is_numeric($id) ? $this->em->find(Department::class, (int) $id) : null;

        return null === $department ? null : $this->payloads->department($department);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function agentsFind(array $params): ?array
    {
        $id = $params['id'] ?? null;
        foreach ($this->users->agents() as $agent) {
            if ((string) $agent['id'] === (string) $id) {
                return $agent;
            }
        }

        return null;
    }

    private function department(mixed $id): Department
    {
        return $this->em->find(Department::class, $this->int($id, 'department_id'))
            ?? throw new \InvalidArgumentException('No such department.');
    }

    // Misc

    /**
     * @param array<string, mixed> $params
     */
    private function broadcast(string $plugin, string $channel, array $params): null
    {
        $event = $this->string($params, 'event');
        $data = \is_array($params['data'] ?? null) ? $params['data'] : [];
        $this->broadcaster->broadcast(new BroadcastableEvent($event, $channel, $data + ['plugin' => $plugin]));

        return null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function log(string $plugin, array $params): null
    {
        $message = '['.$plugin.'] '.(\is_scalar($params['message'] ?? null) ? (string) $params['message'] : '');
        $context = ['plugin' => $plugin];
        if (\array_key_exists('data', $params) && null !== $params['data']) {
            $context['data'] = $params['data'];
        }

        match ($params['level'] ?? 'info') {
            'debug' => $this->logger->debug($message, $context),
            'warn', 'warning' => $this->logger->warning($message, $context),
            'error' => $this->logger->error($message, $context),
            default => $this->logger->info($message, $context),
        };

        return null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function emit(string $plugin, array $params): null
    {
        $this->registry?->emit($plugin, $this->string($params, 'hook'), $params['data'] ?? []);

        return null;
    }

    /**
     * @return array{id: int|string, name: ?string, email: ?string}|null
     */
    private function currentUser(): ?array
    {
        $id = $this->actorId();
        if (null === $id) {
            return null;
        }
        $user = $this->users->find($id);

        return ['id' => $id, 'name' => $user['name'] ?? null, 'email' => $user['email'] ?? null];
    }

    // Input

    /**
     * @param array<string, mixed>  $filter
     * @param array<string, string> $fields
     */
    private function applyFilter(\Doctrine\ORM\QueryBuilder $query, array $filter, array $fields, string $method): void
    {
        $n = 0;
        foreach ($filter as $key => $value) {
            $field = $fields[$key] ?? throw new \InvalidArgumentException(sprintf('%s cannot filter on "%s".', $method, $key));
            $parameter = 'f'.$n++;
            if (\is_array($value)) {
                $query->andWhere(sprintf('%s IN (:%s)', $field, $parameter))->setParameter($parameter, array_values($value));
            } elseif (null === $value) {
                $query->andWhere(sprintf('%s IS NULL', $field));
            } else {
                $query->andWhere(sprintf('%s = :%s', $field, $parameter))->setParameter($parameter, $value);
            }
        }
    }

    private function priority(mixed $priority): ?string
    {
        if (null === $priority) {
            return null;
        }
        if (!\in_array($priority, self::PRIORITIES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown priority: %s', \is_scalar($priority) ? (string) $priority : get_debug_type($priority)));
        }

        return $priority;
    }

    private function text(mixed $value): ?string
    {
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            return null;
        }
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    private function int(mixed $value, string $name): int
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a number.', $name));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function string(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(sprintf('%s is required.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function scalar(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (!\is_string($value) && !\is_int($value)) {
            throw new \InvalidArgumentException(sprintf('%s is required.', $key));
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function array(array $params, string $key, bool $required = false): array
    {
        $value = $params[$key] ?? null;
        if (null === $value && !$required) {
            return [];
        }
        if (!\is_array($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be an object.', $key));
        }

        return $value;
    }
}
