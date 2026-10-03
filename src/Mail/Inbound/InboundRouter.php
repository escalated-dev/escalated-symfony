<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Mail\MessageIdUtil;
use Escalated\Symfony\Repository\TicketRepository;

/**
 * Resolves an inbound email to an existing ticket via canonical
 * Message-ID parsing + signed Reply-To verification.
 *
 * With an inbound secret configured, only the signed Reply-To on
 * toEmail (reply+{id}.{hmac8}@...) identifies a ticket, verified via
 * MessageIdUtil::verifyReplyTo (hash_equals, timing-safe). Message-IDs
 * and references are guessable, so they are not trusted once a secret
 * exists.
 *
 * Without a secret (first match wins):
 *   1. In-Reply-To parsed via MessageIdUtil::parseTicketIdFromMessageId.
 *   2. References parsed via MessageIdUtil, each id in order.
 *   3. Subject-line reference tag [{PREFIX}-...].
 *
 * A resolved ticket is only a lookup: {@see InboundEmailService} still
 * requires the sender to be the ticket's requester.
 *
 * Mirrors the NestJS reference and the per-framework inbound-verify
 * PRs plus the greenfield .NET / Spring / Go / Phoenix routers.
 */
class InboundRouter
{
    private const SUBJECT_REF_PATTERN = '/\[([A-Z]+-[0-9A-Z-]+)\]/';

    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly string $inboundSecret = '',
    ) {
    }

    /**
     * Resolve the inbound email to an existing ticket, or `null`
     * when no match (caller should create a new ticket).
     */
    public function resolveTicket(InboundMessage $message): ?Ticket
    {
        // With a secret, only the signed Reply-To on the recipient address.
        if ('' !== $this->inboundSecret) {
            $verified = '' !== $message->toEmail
                ? MessageIdUtil::verifyReplyTo($message->toEmail, $this->inboundSecret)
                : null;
            if (null === $verified) {
                return null;
            }
            $ticket = $this->ticketRepository->find($verified);

            return $ticket instanceof Ticket ? $ticket : null;
        }

        // 1 + 2. Parse canonical Message-IDs out of our own headers.
        foreach (self::candidateHeaderMessageIds($message) as $raw) {
            $ticketId = MessageIdUtil::parseTicketIdFromMessageId($raw);
            if (null !== $ticketId) {
                $ticket = $this->ticketRepository->find($ticketId);
                if ($ticket instanceof Ticket) {
                    return $ticket;
                }
            }
        }

        // 3. Subject-line reference tag.
        if (preg_match(self::SUBJECT_REF_PATTERN, $message->subject, $m)) {
            $ticket = $this->ticketRepository->findByReference($m[1]);
            if ($ticket instanceof Ticket) {
                return $ticket;
            }
        }

        return null;
    }

    /**
     * Return every candidate Message-ID from the inbound headers in
     * the order the mail client sent them.
     *
     * @return list<string>
     */
    public static function candidateHeaderMessageIds(InboundMessage $message): array
    {
        $ids = [];
        if (!empty($message->inReplyTo)) {
            $ids[] = trim($message->inReplyTo);
        }
        if (!empty($message->references)) {
            foreach (preg_split('/\s+/', trim($message->references)) ?: [] as $ref) {
                $ref = trim($ref);
                if ('' !== $ref) {
                    $ids[] = $ref;
                }
            }
        }

        return $ids;
    }
}
