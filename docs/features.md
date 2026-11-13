# Features

## Features

- **Ticket lifecycle** — Create, assign, reply, resolve, close, reopen with configurable status transitions
- **SLA engine** — Per-priority response and resolution targets, business hours calculation, automatic breach detection
- **Agent dashboard** — Ticket queue with filters, internal notes, canned responses
- **Customer portal** — Self-service ticket creation, replies, and status tracking
- **Admin panel** — Manage departments, SLA policies, tags, and view reports
- **File attachments** — Drag-and-drop uploads with configurable storage and size limits
- **Activity timeline** — Full audit log of every action on every ticket
- **Email notifications** — Configurable per-event notifications
- **Department routing** — Organize agents into departments with auto-assignment
- **Tagging system** — Categorize tickets with colored tags
- **Ticket splitting** — Split a reply into a new standalone ticket while preserving the original context
- **Ticket snooze** — Snooze tickets with presets (1h, 4h, tomorrow, next week); `php bin/console escalated:wake-snoozed-tickets` Console command auto-wakes them on schedule
- **Saved views / custom queues** — Save, name, and share filter presets as reusable ticket views
- **Embeddable support widget** — Lightweight `<script>` widget with KB search, ticket form, and status check
- **Email threading** — Outbound emails include proper `In-Reply-To` and `References` headers for correct threading in mail clients
- **Branded email templates** — Configurable logo, primary color, and footer text for all outbound emails
- **Real-time broadcasting** — Opt-in broadcasting via Mercure with automatic polling fallback
- **Knowledge base toggle** — Enable or disable the public knowledge base from admin settings

## Configurable ticket access and routing

Optional department visibility, ticket accounts, account/type rule conditions and targeted SLAs are documented in [Ticket access and routing](ticket-access-and-routing.md). All options default off; apply the additive migration before enabling them.
