<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\TicketService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates the full inbound email pipeline:
 *
 *     parser output → router resolution → reply-on-existing or
 *     create-new-ticket
 *
 * Called from {@see \Escalated\Symfony\Controller\InboundEmailController}
 * after the parser normalizes the provider payload. Mirrors the
 * NestJS reference InboundRouterService and the .NET / Spring / Go /
 * Phoenix ports.
 *
 * Attachment persistence is scoped out: provider-hosted attachments
 * (Mailgun) carry their downloadUrl through to
 * {@see ProcessResult::$pendingAttachmentDownloads} so a follow-up
 * worker can fetch + persist out-of-band.
 */
final class InboundEmailService
{
    public const OUTCOME_REPLIED_TO_EXISTING = 'replied_to_existing';
    public const OUTCOME_CREATED_NEW = 'created_new';
    public const OUTCOME_SKIPPED = 'skipped';

    private LoggerInterface $logger;

    public function __construct(
        private readonly InboundRouter $router,
        private readonly TicketService $tickets,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Process a parsed inbound message. Returns a ProcessResult
     * carrying the outcome (matched + reply id, new ticket id, or
     * skipped).
     */
    public function process(InboundMessage $message): ProcessResult
    {
        $ticket = $this->router->resolveTicket($message);

        if ($ticket instanceof Ticket) {
            $reply = $this->tickets->addInboundEmailReply($ticket, $message->body());

            return new ProcessResult(
                outcome: self::OUTCOME_REPLIED_TO_EXISTING,
                ticketId: $ticket->getId(),
                replyId: $reply->getId(),
                pendingAttachmentDownloads: self::pendingDownloads($message),
            );
        }

        if (self::isNoiseEmail($message)) {
            return new ProcessResult(
                outcome: self::OUTCOME_SKIPPED,
                ticketId: null,
                replyId: null,
                pendingAttachmentDownloads: [],
            );
        }

        $subject = trim($message->subject) !== '' ? $message->subject : '(no subject)';
        $newTicket = $this->tickets->create([
            'subject' => $subject,
            'description' => $message->body(),
            'guest_name' => $message->fromName ?? $message->fromEmail,
            'guest_email' => $message->fromEmail,
            'priority' => Ticket::PRIORITY_MEDIUM,
        ]);

        $this->logger->info(
            '[InboundEmailService] Created ticket #{ticketId} from inbound email',
            ['ticketId' => $newTicket->getId()]
        );

        return new ProcessResult(
            outcome: self::OUTCOME_CREATED_NEW,
            ticketId: $newTicket->getId(),
            replyId: null,
            pendingAttachmentDownloads: self::pendingDownloads($message),
        );
    }

    /**
     * Noise emails: empty body + empty subject, or common
     * bounce/no-reply / SNS confirmation senders.
     */
    public static function isNoiseEmail(InboundMessage $message): bool
    {
        if (strcasecmp($message->fromEmail, 'no-reply@sns.amazonaws.com') === 0) {
            return true;
        }

        return trim($message->body()) === '' && trim($message->subject) === '';
    }

    /**
     * Provider-hosted attachments the host app should download
     * out-of-band (Mailgun hosts content behind a URL for large
     * files). Empty when all attachments came inline.
     *
     * @return list<PendingAttachment>
     */
    private static function pendingDownloads(InboundMessage $message): array
    {
        $pending = [];
        foreach ($message->attachments as $attachment) {
            if ($attachment->downloadUrl !== null && $attachment->downloadUrl !== '' && $attachment->content === null) {
                $pending[] = new PendingAttachment(
                    name: $attachment->name,
                    contentType: $attachment->contentType,
                    sizeBytes: $attachment->sizeBytes,
                    downloadUrl: $attachment->downloadUrl,
                );
            }
        }

        return $pending;
    }
}
