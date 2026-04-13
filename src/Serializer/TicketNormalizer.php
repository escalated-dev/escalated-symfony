<?php

declare(strict_types=1);

namespace Escalated\Symfony\Serializer;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Ticket;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Adds computed fields to serialized Ticket objects.
 *
 * The frontend expects these convenience properties on every ticket:
 *   - requester_name
 *   - requester_email
 *   - last_reply_at
 *   - last_reply_author
 *   - is_live_chat
 *   - is_snoozed
 *   - chat_session_id
 *   - chat_started_at
 *   - chat_messages
 *   - chat_metadata
 *   - requester_ticket_count
 *   - related_tickets
 *   - activity.created_at_human
 */
#[Autoconfigure(tags: ['serializer.normalizer'])]
class TicketNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'ticket_normalizer_already_called';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param Ticket $object
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $context[self::ALREADY_CALLED] = true;

        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($object, $format, $context);

        // requester_name: guest name when available, null otherwise
        if (!array_key_exists('requester_name', $data)) {
            $data['requester_name'] = $object->getGuestName();
        }

        // requester_email: guest email when available, null otherwise
        if (!array_key_exists('requester_email', $data)) {
            $data['requester_email'] = $object->getGuestEmail();
        }

        // last_reply_at / last_reply_author: derived from the replies collection
        if (!array_key_exists('last_reply_at', $data) || !array_key_exists('last_reply_author', $data)) {
            $lastReply = $object->getReplies()->first() ?: null;

            if (!array_key_exists('last_reply_at', $data)) {
                $data['last_reply_at'] = $lastReply?->getCreatedAt()->format(\DateTimeInterface::ATOM);
            }

            if (!array_key_exists('last_reply_author', $data)) {
                $data['last_reply_author'] = null;
                if (null !== $lastReply) {
                    $meta = $lastReply->getMetadata();
                    $data['last_reply_author'] = $meta['author_name'] ?? null;
                }
            }
        }

        // is_live_chat: status is "live" and channel is "chat"
        if (!array_key_exists('is_live_chat', $data)) {
            $data['is_live_chat'] = Ticket::STATUS_LIVE === $object->getStatus()
                && Ticket::CHANNEL_CHAT === $object->getChannel();
        }

        // is_snoozed: snoozed_until is set and in the future
        if (!array_key_exists('is_snoozed', $data)) {
            $snoozedUntil = $object->getSnoozedUntil();
            $data['is_snoozed'] = null !== $snoozedUntil && $snoozedUntil > new \DateTimeImmutable();
        }

        $isDetail = str_contains($context['groups'][0] ?? '', 'detail');

        // chat_session_id & chat_started_at: from the associated ChatSession
        $chatSession = $this->findChatSession($object);
        if (!array_key_exists('chat_session_id', $data)) {
            $data['chat_session_id'] = $chatSession?->getId();
        }
        if (!array_key_exists('chat_started_at', $data)) {
            $data['chat_started_at'] = $chatSession?->getCreatedAt()->format(\DateTimeInterface::ATOM);
        }

        // chat_metadata: from the ticket entity
        if (!array_key_exists('chat_metadata', $data)) {
            $data['chat_metadata'] = $object->getChatMetadata();
        }

        // chat_messages: replies on the ticket serialized as chat messages (detail only)
        if ($isDetail && !array_key_exists('chat_messages', $data)) {
            $data['chat_messages'] = [];
            foreach ($object->getReplies() as $reply) {
                $data['chat_messages'][] = [
                    'id' => $reply->getId(),
                    'body' => $reply->getBody(),
                    'author_id' => $reply->getAuthorId(),
                    'is_agent' => null !== $reply->getAuthorClass(),
                    'created_at' => $reply->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ];
            }
        }

        // requester_ticket_count: total tickets from the same requester
        if (!array_key_exists('requester_ticket_count', $data)) {
            $data['requester_ticket_count'] = $this->countRequesterTickets($object);
        }

        // related_tickets: other tickets from the same requester (detail only)
        if ($isDetail && !array_key_exists('related_tickets', $data)) {
            $data['related_tickets'] = $this->findRelatedTickets($object);
        }

        // activity.created_at_human: human-readable timestamps on activities
        if ($isDetail && isset($data['activities']) && \is_array($data['activities'])) {
            foreach ($data['activities'] as &$activity) {
                if (isset($activity['created_at']) && !isset($activity['created_at_human'])) {
                    try {
                        $dt = new \DateTimeImmutable($activity['created_at']);
                        $activity['created_at_human'] = $this->humanReadableTime($dt);
                    } catch (\Exception) {
                        $activity['created_at_human'] = $activity['created_at'];
                    }
                }
            }
            unset($activity);
        }

        return $data;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof Ticket;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Ticket::class => false,
        ];
    }

    private function findChatSession(Ticket $ticket): ?ChatSession
    {
        if (null === $ticket->getId()) {
            return null;
        }

        return $this->em->getRepository(ChatSession::class)->findOneBy(['ticket' => $ticket->getId()]);
    }

    private function countRequesterTickets(Ticket $ticket): int
    {
        $requesterId = $ticket->getRequesterId();
        if (null === $requesterId) {
            // For guest tickets, count by guest email
            $email = $ticket->getGuestEmail();
            if (null === $email) {
                return 1;
            }

            return (int) $this->em->getRepository(Ticket::class)
                ->createQueryBuilder('t')
                ->select('COUNT(t.id)')
                ->where('t.guestEmail = :email')
                ->andWhere('t.deletedAt IS NULL')
                ->setParameter('email', $email)
                ->getQuery()
                ->getSingleScalarResult();
        }

        return (int) $this->em->getRepository(Ticket::class)
            ->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.requesterId = :requesterId')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('requesterId', $requesterId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<array{reference: string, subject: string, status: string}>
     */
    private function findRelatedTickets(Ticket $ticket): array
    {
        $requesterId = $ticket->getRequesterId();
        $qb = $this->em->getRepository(Ticket::class)->createQueryBuilder('t')
            ->select('t.reference', 't.subject', 't.status')
            ->where('t.id != :currentId')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('currentId', $ticket->getId())
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(10);

        if (null !== $requesterId) {
            $qb->andWhere('t.requesterId = :requesterId')
                ->setParameter('requesterId', $requesterId);
        } else {
            $email = $ticket->getGuestEmail();
            if (null === $email) {
                return [];
            }
            $qb->andWhere('t.guestEmail = :email')
                ->setParameter('email', $email);
        }

        return $qb->getQuery()->getResult();
    }

    private function humanReadableTime(\DateTimeImmutable $dateTime): string
    {
        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $dateTime->getTimestamp();

        if ($diff < 60) {
            return 'just now';
        }

        if ($diff < 3600) {
            $minutes = (int) floor($diff / 60);

            return sprintf('%d minute%s ago', $minutes, 1 === $minutes ? '' : 's');
        }

        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);

            return sprintf('%d hour%s ago', $hours, 1 === $hours ? '' : 's');
        }

        if ($diff < 604800) {
            $days = (int) floor($diff / 86400);

            return sprintf('%d day%s ago', $days, 1 === $days ? '' : 's');
        }

        return $dateTime->format('M j, Y g:i A');
    }
}
