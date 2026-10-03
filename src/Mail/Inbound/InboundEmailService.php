<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

use Doctrine\Persistence\ManagerRegistry;
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
class InboundEmailService
{
    public const OUTCOME_REPLIED_TO_EXISTING = 'replied_to_existing';
    public const OUTCOME_CREATED_NEW = 'created_new';
    public const OUTCOME_SKIPPED = 'skipped';

    private LoggerInterface $logger;

    public function __construct(
        private readonly InboundRouter $router,
        private readonly TicketService $tickets,
        ?LoggerInterface $logger = null,
        private readonly ?ManagerRegistry $doctrine = null,
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

        // A thread match alone is not enough: the sender must also be the
        // ticket's requester, and the author is always taken from the
        // ticket, never from the unauthenticated From header.
        if ($ticket instanceof Ticket && $this->isFromRequester($ticket, $message)) {
            $reply = null === $ticket->getRequesterClass()
                ? $this->tickets->addInboundEmailReply($ticket, $message->body())
                : $this->tickets->addInboundEmailReply(
                    $ticket,
                    $message->body(),
                    $ticket->getRequesterId(),
                    $ticket->getRequesterClass(),
                );

            return new ProcessResult(
                outcome: self::OUTCOME_REPLIED_TO_EXISTING,
                ticketId: $ticket->getId(),
                replyId: $reply->getId(),
                pendingAttachmentDownloads: self::pendingDownloads($message),
            );
        }

        if ($ticket instanceof Ticket) {
            $this->logger->info(
                '[InboundEmailService] Inbound email matched ticket #{ticketId} but not its requester; opening a new ticket',
                ['ticketId' => $ticket->getId()]
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

        $subject = '' !== trim($message->subject) ? $message->subject : '(no subject)';
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
     * True when the From address is the ticket's requester: its guest
     * email, or the email of the requester user (loaded through the
     * requester class's entity manager), compared case-insensitively.
     * Staff identity is never derived from From: an agent replying by
     * email is not the requester, so the message becomes a new ticket.
     */
    private function isFromRequester(Ticket $ticket, InboundMessage $message): bool
    {
        $sender = self::normalizeEmail($message->fromEmail);
        if ('' === $sender) {
            return false;
        }

        $guestEmail = $ticket->getGuestEmail();
        if (null !== $guestEmail && self::normalizeEmail($guestEmail) === $sender) {
            return true;
        }

        $requesterClass = $ticket->getRequesterClass();
        $requesterId = $ticket->getRequesterId();
        if (null === $requesterClass || null === $requesterId || null === $this->doctrine || !class_exists($requesterClass)) {
            return false;
        }

        $requester = $this->doctrine->getManagerForClass($requesterClass)?->find($requesterClass, $requesterId);
        if (!is_object($requester) || !method_exists($requester, 'getEmail')) {
            return false;
        }

        return self::normalizeEmail((string) $requester->getEmail()) === $sender;
    }

    private static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Noise emails: empty body + empty subject, or common
     * bounce/no-reply / SNS confirmation senders.
     */
    public static function isNoiseEmail(InboundMessage $message): bool
    {
        if (0 === strcasecmp($message->fromEmail, 'no-reply@sns.amazonaws.com')) {
            return true;
        }

        return '' === trim($message->body()) && '' === trim($message->subject);
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
            if (null !== $attachment->downloadUrl && '' !== $attachment->downloadUrl && null === $attachment->content) {
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
