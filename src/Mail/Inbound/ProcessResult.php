<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Outcome record returned by {@see InboundEmailService::process()}.
 *
 * The {@see $outcome} is one of:
 *   - InboundEmailService::OUTCOME_REPLIED_TO_EXISTING
 *   - InboundEmailService::OUTCOME_CREATED_NEW
 *   - InboundEmailService::OUTCOME_SKIPPED
 */
final class ProcessResult
{
    /**
     * @param list<PendingAttachment> $pendingAttachmentDownloads
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?int $ticketId,
        public readonly ?int $replyId,
        public readonly array $pendingAttachmentDownloads,
    ) {
    }
}
