<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail;

use Escalated\Symfony\Entity\Ticket;
use Symfony\Component\Mime\Email;

/**
 * Adds RFC 2822 threading headers (Message-ID, In-Reply-To, References)
 * to outgoing emails so mail clients group replies into conversations.
 */
class ThreadingHeadersService
{
    public function __construct(
        private readonly string $mailDomain = 'escalated.localhost',
    ) {
    }

    /**
     * Generate a deterministic Message-ID for a ticket reply.
     */
    public function generateMessageId(Ticket $ticket, ?int $replyId = null): string
    {
        $local = null !== $replyId
            ? sprintf('escalated.%s.%d', $ticket->getReference(), $replyId)
            : sprintf('escalated.%s', $ticket->getReference());

        return sprintf('%s@%s', $local, $this->mailDomain);
    }

    /**
     * Generate the root Message-ID for a ticket (used as thread anchor).
     */
    public function getRootMessageId(Ticket $ticket): string
    {
        return $this->generateMessageId($ticket);
    }

    /**
     * Apply threading headers to an Email message.
     */
    public function applyHeaders(Email $email, Ticket $ticket, ?int $replyId = null): Email
    {
        $headers = $email->getHeaders();

        $messageId = $this->generateMessageId($ticket, $replyId);
        $rootId = $this->getRootMessageId($ticket);

        $headers->addIdHeader('Message-ID', $messageId);

        if (null !== $replyId) {
            $headers->addIdHeader('In-Reply-To', $rootId);
            $headers->addIdHeader('References', $rootId);
        }

        return $email;
    }

    public function getMailDomain(): string
    {
        return $this->mailDomain;
    }
}
