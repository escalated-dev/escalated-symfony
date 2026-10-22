<?php

declare(strict_types=1);

namespace Escalated\Symfony\Import;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Contract\ImportAdapterInterface;
use Escalated\Symfony\Entity\Attachment;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\CustomField;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\ImportJob;
use Escalated\Symfony\Entity\ImportSourceMap;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\SatisfactionRating;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Rendering\UserDirectory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs an import, as escalated-laravel's ImportService::run: walk the
 * adapter's entity types in order inside {@see ImportContext::suppress()},
 * resume each from its saved cursor and counters, write every record not yet
 * in the source map, log failures, save progress after each batch, and stop
 * between batches when the job has been paused. A completed job drops its
 * credentials.
 *
 * Contacts the host already has as users map to those users. Others become
 * Escalated contacts (a bundle cannot create host users); their tickets are
 * guest tickets linked to the contact.
 */
class ImportService
{
    private const CONTACT_PREFIX = 'contact:';

    private const STATUSES = [
        Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_WAITING_ON_CUSTOMER, Ticket::STATUS_WAITING_ON_AGENT,
        Ticket::STATUS_ESCALATED, Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED, Ticket::STATUS_REOPENED,
    ];

    private const PRIORITIES = [
        Ticket::PRIORITY_LOW, Ticket::PRIORITY_MEDIUM, Ticket::PRIORITY_HIGH, Ticket::PRIORITY_URGENT, Ticket::PRIORITY_CRITICAL,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportAdapterRegistry $adapters,
        private readonly ImportCredentials $credentials,
        private readonly UserDirectory $users,
        private readonly string $userClass,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param (callable(string, array<string, mixed>): void)|null $onProgress called after each batch
     */
    public function run(ImportJob $job, ?callable $onProgress = null): void
    {
        $adapter = $this->adapters->find($job->getPlatform());
        if (null === $adapter) {
            $job->setStatus('failed');
            $this->em->flush();

            throw new \RuntimeException(sprintf("No adapter found for platform '%s'.", $job->getPlatform()));
        }

        if ('importing' !== $job->getStatus()) {
            $job->transitionTo('importing');
        }
        $job->setStartedAt($job->getStartedAt() ?? new \DateTimeImmutable());
        $this->em->flush();

        $credentials = $this->credentials->open($job->getSealedCredentials()) ?? [];

        ImportContext::suppress(function () use ($job, $adapter, $credentials, $onProgress): void {
            foreach ($adapter->entityTypes() as $entityType) {
                if ($this->pausedNow($job)) {
                    return;
                }
                if (!$this->importEntityType($job, $adapter, $entityType, $credentials, $onProgress)) {
                    return;
                }
            }
        });

        if ($this->pausedNow($job)) {
            return;
        }

        $job->setStatus('completed')
            ->setCompletedAt(new \DateTimeImmutable())
            ->setSealedCredentials(null);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $credentials
     *
     * @return bool false when the job was paused mid-type
     */
    private function importEntityType(ImportJob $job, ImportAdapterInterface $adapter, string $entityType, array $credentials, ?callable $onProgress): bool
    {
        $cursor = $job->getEntityCursor($entityType);
        $saved = $job->getProgress()[$entityType] ?? [];
        $processed = (int) ($saved['processed'] ?? 0);
        $skipped = (int) ($saved['skipped'] ?? 0);
        $failed = (int) ($saved['failed'] ?? 0);

        do {
            $result = $adapter->extract($entityType, $credentials, $cursor);
            if (null !== $result->totalCount) {
                $job->updateEntityProgress($entityType, total: $result->totalCount);
            }

            foreach ($result->records as $record) {
                $sourceId = isset($record['source_id']) ? (string) $record['source_id'] : '';
                if ('' === $sourceId) {
                    ++$failed;
                    continue;
                }
                if (null !== $this->resolve($job, $entityType, $sourceId)) {
                    ++$skipped;
                    continue;
                }

                try {
                    $escalatedId = $this->persistRecord($job, $entityType, $record);
                    $this->em->persist(new ImportSourceMap($job, $entityType, $sourceId, (string) $escalatedId));
                    $this->em->flush();
                    ++$processed;
                } catch (\Throwable $e) {
                    ++$failed;
                    $this->logger->warning(sprintf('[Escalated\\Import] job %s %s %s: %s', $job->getId(), $entityType, $sourceId, $e->getMessage()));
                    if (!$this->em->isOpen()) {
                        throw $e;
                    }
                    $job->appendError($entityType, $sourceId, $e->getMessage());
                }
            }

            $cursor = $result->cursor;
            $job->updateEntityProgress($entityType, processed: $processed, skipped: $skipped, failed: $failed, cursor: $cursor);
            $this->em->flush();

            if (null !== $onProgress) {
                $onProgress($entityType, $job->getProgress()[$entityType]);
            }

            if ($this->pausedNow($job)) {
                return false;
            }
        } while (!$result->isExhausted());

        return true;
    }

    /** Re-reads the status the admin may have changed from another request: paused or cancelled. */
    private function pausedNow(ImportJob $job): bool
    {
        $status = $this->em->getConnection()->fetchOne(
            'SELECT status FROM '.$this->em->getClassMetadata(ImportJob::class)->getTableName().' WHERE id = ?',
            [$job->getId()],
        );

        // Paused, or cancelled (failed) from the Progress screen.
        if (!\in_array($status, ['paused', 'failed'], true)) {
            return false;
        }
        $job->setStatus($status);

        return true;
    }

    private function resolve(ImportJob $job, string $entityType, string $sourceId): ?string
    {
        $map = $this->em->getRepository(ImportSourceMap::class)->findOneBy([
            'importJob' => $job->getId(),
            'entityType' => $entityType,
            'sourceId' => $sourceId,
        ]);

        return $map?->getEscalatedId();
    }

    /** @param array<string, mixed> $record */
    private function persistRecord(ImportJob $job, string $entityType, array $record): int|string
    {
        return match ($entityType) {
            'agents' => $this->persistAgent($record),
            'tags' => $this->persistTag($record),
            'custom_fields' => $this->persistCustomField($record),
            'contacts' => $this->persistContact($record),
            'departments' => $this->persistDepartment($record),
            'tickets' => $this->persistTicket($job, $record),
            'replies' => $this->persistReply($job, $record),
            'attachments' => $this->persistAttachment($job, $record),
            'satisfaction_ratings' => $this->persistSatisfactionRating($job, $record),
            default => throw new \RuntimeException(sprintf('Unknown entity type: %s', $entityType)),
        };
    }

    /** @param array<string, mixed> $record */
    private function persistAgent(array $record): int|string
    {
        $email = (string) ($record['email'] ?? '');
        $id = $this->users->findIdByEmail($email);
        if (null === $id) {
            throw new \RuntimeException(sprintf("Agent with email '%s' not found in host application.", $email));
        }

        return $id;
    }

    /** @param array<string, mixed> $record */
    private function persistContact(array $record): int|string
    {
        $email = (string) ($record['email'] ?? '');
        if ('' === $email) {
            throw new \RuntimeException('Contact has no email.');
        }

        $userId = $this->users->findIdByEmail($email);
        if (null !== $userId) {
            return $userId;
        }

        $normalized = Contact::normalizeEmail($email);
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => $normalized]);
        if (null === $contact) {
            $contact = (new Contact())->setEmail($normalized)->setName($this->string($record, 'name'));
            $this->em->persist($contact);
            $this->em->flush();
        }

        return self::CONTACT_PREFIX.$contact->getId();
    }

    /** @param array<string, mixed> $record */
    private function persistTag(array $record): int
    {
        $name = (string) ($record['name'] ?? '');
        $slug = $this->slug($name);
        $tag = $this->em->getRepository(Tag::class)->findOneBy(['slug' => $slug]);
        if (null === $tag) {
            $tag = (new Tag())->setName($name)->setSlug($slug);
            $this->em->persist($tag);
            $this->em->flush();
        }

        return (int) $tag->getId();
    }

    /** @param array<string, mixed> $record */
    private function persistDepartment(array $record): int
    {
        $name = (string) ($record['name'] ?? '');
        $slug = $this->slug($name);
        $department = $this->em->getRepository(Department::class)->findOneBy(['slug' => $slug]);
        if (null === $department) {
            $department = (new Department())->setName($name)->setSlug($slug)->setIsActive(true);
            $this->em->persist($department);
            $this->em->flush();
        }

        return (int) $department->getId();
    }

    /** @param array<string, mixed> $record */
    private function persistCustomField(array $record): int
    {
        $name = (string) ($record['name'] ?? '');
        $slug = $this->slug($name, '_');
        $field = $this->em->getRepository(CustomField::class)->findOneBy(['slug' => $slug]);
        if (null === $field) {
            $field = (new CustomField())->setName($name)->setSlug($slug)
                ->setFieldType((string) ($record['type'] ?? 'text'))
                ->setOptions(\is_array($record['options'] ?? null) ? $record['options'] : null);
            $this->em->persist($field);
            $this->em->flush();
        }

        return (int) $field->getId();
    }

    /** @param array<string, mixed> $record */
    private function persistTicket(ImportJob $job, array $record): int
    {
        $subject = $this->string($record, 'title') ?? $this->string($record, 'subject') ?? 'Imported ticket';
        $status = (string) ($record['status'] ?? '');
        $priority = (string) ($record['priority'] ?? '');

        $ticket = new Ticket();
        $ticket->setSubject($subject)
            ->setDescription($this->string($record, 'description') ?? $this->string($record, 'body') ?? $subject)
            // As Laravel's TicketStatus::tryFrom / TicketPriority::tryFrom.
            ->setStatus(\in_array($status, self::STATUSES, true) ? $status : Ticket::STATUS_OPEN)
            ->setPriority(\in_array($priority, self::PRIORITIES, true) ? $priority : Ticket::PRIORITY_MEDIUM)
            ->setMetadata(\is_array($record['metadata'] ?? null) ? $record['metadata'] : null);

        if (null !== $requester = $this->sourceRef($job, 'contacts', $record['requester_source_id'] ?? null)) {
            if (str_starts_with($requester, self::CONTACT_PREFIX)) {
                $contact = $this->em->find(Contact::class, (int) substr($requester, \strlen(self::CONTACT_PREFIX)));
                if ($contact instanceof Contact) {
                    $ticket->setContact($contact)->setGuestEmail($contact->getEmail())->setGuestName($contact->getName());
                }
            } else {
                $ticket->setRequesterClass($this->userClass)->setRequesterId($requester);
            }
        }
        if (null !== $assignee = $this->sourceRef($job, 'agents', $record['assignee_source_id'] ?? null)) {
            $ticket->setAssignedTo($assignee);
        }
        if (null !== $department = $this->sourceRef($job, 'departments', $record['department_source_id'] ?? null)) {
            $ticket->setDepartment($this->em->find(Department::class, (int) $department));
        }
        foreach ((array) ($record['tag_source_ids'] ?? []) as $tagSource) {
            $tagId = $this->sourceRef($job, 'tags', $tagSource);
            $tag = null !== $tagId ? $this->em->find(Tag::class, (int) $tagId) : null;
            if ($tag instanceof Tag) {
                $ticket->addTag($tag);
            }
        }

        $this->em->persist($ticket);
        $this->em->flush();
        $ticket->setReference($ticket->generateReference());
        $this->em->flush();

        $this->keepTimestamps(Ticket::class, (int) $ticket->getId(), $record);

        return (int) $ticket->getId();
    }

    /** @param array<string, mixed> $record */
    private function persistReply(ImportJob $job, array $record): int
    {
        $ticket = $this->ticketFor($job, $record['ticket_source_id'] ?? null, 'Parent ticket not found for reply.');

        $reply = new Reply();
        $reply->setTicket($ticket)
            ->setBody((string) ($record['body'] ?? ''))
            ->setIsInternalNote((bool) ($record['is_internal_note'] ?? false));

        $author = $this->sourceRef($job, 'agents', $record['author_source_id'] ?? null)
            ?? $this->sourceRef($job, 'contacts', $record['author_source_id'] ?? null);
        if (null !== $author && !str_starts_with($author, self::CONTACT_PREFIX)) {
            $reply->setAuthorClass($this->userClass)->setAuthorId($author);
        }

        $this->em->persist($reply);
        $this->em->flush();
        $this->keepTimestamps(Reply::class, (int) $reply->getId(), $record);

        return (int) $reply->getId();
    }

    /** @param array<string, mixed> $record */
    private function persistAttachment(ImportJob $job, array $record): int
    {
        $parentType = 'ticket' === ($record['parent_type'] ?? 'reply') ? 'ticket' : 'reply';
        $attachment = new Attachment();

        if ('ticket' === $parentType) {
            $attachment->setTicket($this->ticketFor($job, $record['parent_source_id'] ?? null, 'Parent ticket not found for attachment.'));
        } else {
            $replyId = $this->sourceRef($job, 'replies', $record['parent_source_id'] ?? null);
            $reply = null !== $replyId ? $this->em->find(Reply::class, (int) $replyId) : null;
            if (!$reply instanceof Reply) {
                throw new \RuntimeException('Parent reply not found for attachment.');
            }
            $attachment->setReply($reply)->setTicket($reply->getTicket());
        }

        $filename = $this->string($record, 'filename') ?? 'unknown';
        $attachment->setOriginalFilename($filename)
            ->setStoredFilename($filename)
            ->setMimeType($this->string($record, 'mime_type') ?? 'application/octet-stream')
            ->setSize((int) ($record['size'] ?? 0))
            ->setPath((string) ($record['path'] ?? ''));

        $this->em->persist($attachment);
        $this->em->flush();

        return (int) $attachment->getId();
    }

    /** @param array<string, mixed> $record */
    private function persistSatisfactionRating(ImportJob $job, array $record): int
    {
        $rating = (new SatisfactionRating())
            ->setTicket($this->ticketFor($job, $record['ticket_source_id'] ?? null, 'Ticket not found for satisfaction rating.'))
            ->setRating((int) ($record['rating'] ?? $record['score'] ?? 0))
            ->setComment($this->string($record, 'comment'));

        $this->em->persist($rating);
        $this->em->flush();

        return (int) $rating->getId();
    }

    private function ticketFor(ImportJob $job, mixed $sourceId, string $missing): Ticket
    {
        $id = $this->sourceRef($job, 'tickets', $sourceId);
        $ticket = null !== $id ? $this->em->find(Ticket::class, (int) $id) : null;
        if (!$ticket instanceof Ticket) {
            throw new \RuntimeException($missing);
        }

        return $ticket;
    }

    private function sourceRef(ImportJob $job, string $entityType, mixed $sourceId): ?string
    {
        if (!\is_scalar($sourceId) || '' === (string) $sourceId) {
            return null;
        }

        return $this->resolve($job, $entityType, (string) $sourceId);
    }

    /**
     * Keeps the source's created_at / updated_at, which the entities set
     * themselves on construction and update.
     *
     * @param class-string         $class
     * @param array<string, mixed> $record
     */
    private function keepTimestamps(string $class, int $id, array $record): void
    {
        $set = [];
        foreach (['created_at' => 'createdAt', 'updated_at' => 'updatedAt'] as $key => $field) {
            $value = $record[$key] ?? null;
            if (!\is_string($value) || '' === $value) {
                continue;
            }
            try {
                $set[$field] = new \DateTimeImmutable($value);
            } catch (\Exception) {
                continue;
            }
        }
        if ([] === $set) {
            return;
        }

        $qb = $this->em->createQueryBuilder()->update($class, 'e')->where('e.id = :id')->setParameter('id', $id);
        foreach ($set as $field => $value) {
            $qb->set('e.'.$field, ':'.$field)->setParameter($field, $value, 'datetime_immutable');
        }
        $qb->getQuery()->execute();

        $entity = $this->em->find($class, $id);
        if (null !== $entity) {
            $this->em->refresh($entity);
        }
    }

    /** @param array<string, mixed> $record */
    private function string(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;

        return \is_scalar($value) && '' !== (string) $value ? (string) $value : null;
    }

    private function slug(string $name, string $separator = '-'): string
    {
        $ascii = \function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;

        return trim((string) preg_replace('/[^a-z0-9]+/', $separator, strtolower($ascii)), $separator);
    }
}
