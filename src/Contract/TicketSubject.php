<?php

declare(strict_types=1);

namespace Escalated\Symfony\Contract;

/**
 * A host-app model attached to a ticket as its *subject* — what the ticket is
 * about (Project, Customer, asset, …), distinct from the requester.
 */
interface TicketSubject
{
    /** Primary label (e.g. "Acme Website Redesign"). */
    public function ticketSubjectTitle(): string;

    /** Secondary line (e.g. "Project · Acme Corp"). Null to omit. */
    public function ticketSubjectSubtitle(): ?string;

    /** Deep link into the host app. Null for non-clickable. */
    public function ticketSubjectUrl(): ?string;

    /** Accent color (hex or design token). Null for default. */
    public function ticketSubjectColor(): ?string;

    /** Icon slug for the frontend (e.g. "folder"). Null for default. */
    public function ticketSubjectIcon(): ?string;
}
