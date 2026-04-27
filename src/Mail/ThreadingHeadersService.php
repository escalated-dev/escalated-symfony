<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail;

use Escalated\Symfony\Entity\Ticket;
use Symfony\Component\Mime\Email;

/**
 * Adds RFC 5322 threading headers (Message-ID, In-Reply-To, References)
 * plus a signed Reply-To to outgoing emails so mail clients group
 * replies into conversations AND inbound provider webhooks can verify
 * ticket identity even when the mail client strips our Message-ID chain.
 *
 * Delegates to {@see MessageIdUtil} so the format matches the canonical
 * NestJS reference across all Escalated frameworks.
 */
class ThreadingHeadersService
{
    public function __construct(
        private readonly string $mailDomain = 'escalated.localhost',
        private readonly string $inboundSecret = '',
    ) {
    }

    /**
     * Generate a canonical Message-ID for a ticket email. Pass `null`
     * for `$replyId` on the initial ticket notification (the thread
     * anchor); pass a non-null replyId for reply emails so the
     * Message-ID carries `-reply-{replyId}`.
     */
    public function generateMessageId(Ticket $ticket, ?int $replyId = null): string
    {
        return MessageIdUtil::buildMessageId((int) $ticket->getId(), $replyId, $this->mailDomain);
    }

    /**
     * Generate the root Message-ID for a ticket (used as thread anchor).
     */
    public function getRootMessageId(Ticket $ticket): string
    {
        return $this->generateMessageId($ticket);
    }

    /**
     * Build a signed Reply-To address (`reply+{id}.{hmac8}@{domain}`)
     * or `null` when no inbound secret is configured. The HMAC prefix
     * lets inbound provider webhooks verify ticket identity
     * independently of the Message-ID chain.
     */
    public function buildSignedReplyTo(Ticket $ticket): ?string
    {
        if ('' === $this->inboundSecret) {
            return null;
        }

        return MessageIdUtil::buildReplyTo(
            (int) $ticket->getId(),
            $this->inboundSecret,
            $this->mailDomain
        );
    }

    /**
     * Apply threading headers (and Reply-To when signing is enabled)
     * to an Email message.
     */
    public function applyHeaders(Email $email, Ticket $ticket, ?int $replyId = null): Email
    {
        $headers = $email->getHeaders();

        $messageId = $this->generateMessageId($ticket, $replyId);
        $rootId = $this->getRootMessageId($ticket);

        // addIdHeader wraps in angle brackets itself; strip the ones
        // MessageIdUtil adds so we don't end up with <<...>>.
        $headers->addIdHeader('Message-ID', trim($messageId, '<>'));

        if (null !== $replyId) {
            $headers->addIdHeader('In-Reply-To', trim($rootId, '<>'));
            $headers->addIdHeader('References', trim($rootId, '<>'));
        }

        $replyTo = $this->buildSignedReplyTo($ticket);
        if (null !== $replyTo) {
            $email->replyTo($replyTo);
        }

        return $email;
    }

    public function getMailDomain(): string
    {
        return $this->mailDomain;
    }

    public function getInboundSecret(): string
    {
        return $this->inboundSecret;
    }
}
