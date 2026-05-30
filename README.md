<p align="center">
  <a href="docs/translations/README.ar.md">العربية</a> •
  <a href="docs/translations/README.de.md">Deutsch</a> •
  <b>English</b> •
  <a href="docs/translations/README.es.md">Español</a> •
  <a href="docs/translations/README.fr.md">Français</a> •
  <a href="docs/translations/README.it.md">Italiano</a> •
  <a href="docs/translations/README.ja.md">日本語</a> •
  <a href="docs/translations/README.ko.md">한국어</a> •
  <a href="docs/translations/README.nl.md">Nederlands</a> •
  <a href="docs/translations/README.pl.md">Polski</a> •
  <a href="docs/translations/README.pt-BR.md">Português (BR)</a> •
  <a href="docs/translations/README.ru.md">Русский</a> •
  <a href="docs/translations/README.tr.md">Türkçe</a> •
  <a href="docs/translations/README.zh-CN.md">简体中文</a>
</p>

# Escalated for Symfony

An embeddable support ticket system for Symfony applications. Drop-in helpdesk with tickets, replies, departments, tags, SLA policies, and role-based access control.

## Requirements

- PHP 8.2+
- Symfony 6.4 or 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## Installation

```bash
composer require escalated-dev/escalated-symfony
```

### 1. Register the bundle

If Symfony Flex is installed, the bundle is registered automatically. Otherwise, add it to `config/bundles.php`:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. Configure the bundle

Create `config/packages/escalated.yaml`:

```yaml
escalated:
    user_class: App\Entity\User
    route_prefix: /support
    ui_enabled: true
    table_prefix: escalated_
    tickets:
        allow_customer_close: true
        default_priority: medium
    sla:
        enabled: true
        business_hours_only: false
        business_hours:
            start: '09:00'
            end: '17:00'
            timezone: UTC
            days: [1, 2, 3, 4, 5]
```

### Host user key type (UUID / string users)

Escalated stores references to your app's users (ticket requester, assignee,
reply author, etc.). By default those columns are integers. If your `User`
entity's primary key is a **UUID or other string**, set the
`ESCALATED_USER_KEY_TYPE` environment variable so the Doctrine columns are
declared as `VARCHAR(255)` instead of `INTEGER` (and `getUserIdentifier()`
values are no longer cast to `int`):

```dotenv
# .env — one of: int (default) | bigint | uuid | string
ESCALATED_USER_KEY_TYPE=uuid
```

It is read from the environment (not the bundle config) because the Doctrine
type's SQL declaration is resolved outside the container. Existing integer-keyed
installs need no change — the default (`int`) produces the same schema and reads
ids back as `int`. Generate a Doctrine migration after changing it.

### 3. Run migrations

```bash
php bin/console doctrine:migrations:migrate
```

The migration creates all necessary tables (`escalated_tickets`, `escalated_replies`, `escalated_departments`, `escalated_tags`, `escalated_sla_policies`, `escalated_ticket_activities`, `escalated_agent_profiles`).

### 4. Set up security

Add the `ROLE_ESCALATED_ADMIN` role to admin users in your user provider. Agent access is determined by the presence of an `AgentProfile` entity linked to the user.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (Optional) Install Inertia

For the built-in frontend UI, install an Inertia bundle:

```bash
composer require rompetomp/inertia-bundle
# or
composer require skipthedragon/inertia-bundle
```

Set `ui_enabled: false` if you want to use only the API and services with a custom frontend.

## Ticket subjects

A ticket has a **requester** (who raised it) and a **subject line** (free text). You can also attach host-app entities the ticket is *about* — a Project, Customer, asset, and so on — so agents see context and can jump into your app.

Implement `Escalated\Symfony\Contract\TicketSubject` on any attachable model:

```php
use Escalated\Symfony\Contract\TicketSubject;

class Project implements TicketSubject
{
    public function ticketSubjectTitle(): string
    {
        return $this->name;
    }

    public function ticketSubjectSubtitle(): ?string
    {
        return 'Project · '.$this->customer->getName();
    }

    public function ticketSubjectUrl(): ?string
    {
        return $this->urlGenerator->generate('project_show', ['id' => $this->id]);
    }

    public function ticketSubjectColor(): ?string
    {
        return '#2563eb';
    }

    public function ticketSubjectIcon(): ?string
    {
        return 'folder';
    }
}
```

Use `TicketSubjectService` to attach, detach, sync, or list links. `subject_id` is stored as a string so integer, UUID, or string primary keys all work.

```php
$subjectService->attach($ticket, Project::class, (string) $project->getId(), 'project');
$subjectService->detach($ticket, $linkId);
$subjectService->sync($ticket, [
    ['subjectType' => Project::class, 'subjectId' => 'b', 'role' => 'primary'],
    ['subjectType' => Customer::class, 'subjectId' => '42'],
]);
```

Serialized tickets include `subjects[]` with `{ type, id, role, title, subtitle, url, color, icon, missing }` (fallback title `type#id` when no resolver is configured).

Register allowed types and an optional resolver in config:

```yaml
escalated:
    ticket_subjects:
        types:
            - App\Entity\Project
            - App\Entity\Customer
        resolver: App\Escalated\TicketSubjectResolver
```

Implement `TicketSubjectResolverInterface` to map stored `type`/`id` pairs to `TicketSubject` instances for presentation. The agent and API attach endpoints only accept allowlisted types; programmatic `attach()` allows any type when the allowlist is empty.

Agent routes: `POST …/agent/tickets/{reference}/subjects`, `DELETE …/agent/tickets/{reference}/subjects/{linkId}`. API routes mirror under `/api/v1/tickets/…`.

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

## Architecture

### Entities

| Entity | Description |
|---|---|
| `Ticket` | Support ticket with status, priority, SLA tracking |
| `Reply` | Public reply or internal note on a ticket |
| `Department` | Organizational grouping for tickets and agents |
| `Tag` | Labels for categorizing tickets |
| `SlaPolicy` | First response and resolution time targets per priority |
| `TicketActivity` | Audit log of all ticket changes |
| `AgentProfile` | Agent metadata (type, capacity) |
| `TicketSubjectLink` | Polymorphic link from a ticket to a host subject entity |

### Services

| Service | Description |
|---|---|
| `TicketService` | Create, update, transition, reply to tickets |
| `AssignmentService` | Assign/unassign agents, check workload |
| `SlaService` | Attach SLA policies, check for breaches |
| `TicketSubjectService` | Attach/detach/sync host entities a ticket is about |

### Controllers

Routes are organized into four groups, all under the configured `route_prefix`:

- **Customer** (`/customer/tickets`) -- Ticket CRUD for authenticated end-users
- **Agent** (`/agent`) -- Dashboard and ticket management for support agents
- **Admin** (`/admin`) -- Full management of tickets, departments, tags, settings
- **API** (`/api/v1`) -- JSON REST API for external integrations

The admin area includes a runtime settings page at `/admin/settings/public-tickets` (`PublicTicketsSettingsController`) for switching the guest-policy mode — `unassigned`, `guest_user`, or `prompt_signup` — without a redeploy. See [docs.escalated.dev/public-tickets](https://docs.escalated.dev/public-tickets).

### Security

Two Symfony voters control access:

- `ESCALATED_AGENT` -- Granted when the user has an `AgentProfile` record
- `ESCALATED_ADMIN` -- Granted when the user has the `ROLE_ESCALATED_ADMIN` role

### UI Rendering

Controllers use `UiRendererInterface` to render pages. The default `InertiaUiRenderer` delegates to whichever Inertia bundle is installed. To use Twig or another renderer, implement `UiRendererInterface` and override the service in your container config:

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## Status Transitions

Tickets follow a state machine with these transitions:

```
open -> in_progress, waiting_on_customer, waiting_on_agent, escalated, resolved, closed
in_progress -> waiting_on_customer, waiting_on_agent, escalated, resolved, closed
waiting_on_customer -> open, in_progress, resolved, closed
waiting_on_agent -> open, in_progress, escalated, resolved, closed
escalated -> in_progress, resolved, closed
resolved -> reopened, closed
closed -> reopened
reopened -> in_progress, waiting_on_customer, waiting_on_agent, escalated, resolved, closed
```

## Custom Ticket Actions

Host applications can add custom buttons to the agent ticket screen and handle
clicks with normal Symfony events. Define static actions in config:

```yaml
# config/packages/escalated.yaml
escalated:
    ticket_actions:
        - key: sync-crm
          label: 'Sync CRM'
          variant: primary
          confirmation: 'Sync this ticket to the CRM?'
          metadata: { icon: refresh-cw }
```

For dynamic visibility/labels, register a service implementing
`Escalated\Symfony\Contract\TicketActionInterface` — it is auto-tagged and
collected by the registry:

```php
use Escalated\Symfony\Contract\TicketActionInterface;
use Escalated\Symfony\Entity\Ticket;

class SyncCrmTicketAction implements TicketActionInterface
{
    public function getKey(): string { return 'sync-crm'; }
    public function getLabel(Ticket $ticket, mixed $user): string { return 'Sync CRM'; }
    public function isVisible(Ticket $ticket, mixed $user): bool { return true; }
    public function isEnabled(Ticket $ticket, mixed $user): bool { return !($ticket->getMetadata()['crm_synced'] ?? false); }
    public function getVariant(): string { return 'primary'; }
    public function getConfirmation(Ticket $ticket, mixed $user): ?string { return 'Sync this ticket to the CRM?'; }
    public function getMetadata(Ticket $ticket, mixed $user): array { return ['icon' => 'refresh-cw']; }
}
```

The agent ticket show exposes visible actions as `customActions`, and the API
detail response as `custom_actions` (each with `url` + `method`). Triggering one
(`POST /agent/tickets/{reference}/actions/{actionKey}`) validates the action is
visible (404) and enabled (403), then dispatches
`Escalated\Symfony\Event\TicketCustomActionTriggeredEvent`:

```php
use Escalated\Symfony\Event\TicketCustomActionTriggeredEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class CrmSyncListener
{
    public function __invoke(TicketCustomActionTriggeredEvent $event): void
    {
        if ('sync-crm' !== $event->action) {
            return;
        }
        // $event->ticket, $event->userId, $event->payload, $event->metadata
    }
}
```

The event exposes `ticket`, `action`, `userId`, `payload`, and `metadata`.
Escalated also records an internal note on the ticket whenever an action fires,
for auditability.

## Translations

Escalated bundles ship their UI strings from a single source of truth:
[`escalated-dev/locale`](https://github.com/escalated-dev/escalated-locale).
The package is pulled in as a Composer dependency and its `translations/`
directory is prepended onto `framework.translator.paths` automatically by
the bundle's `prependExtension` hook.

The plugin-local `translations/` directory and your application's
`translations/` directory both override the central package on a key-by-key
basis. To override a single string, drop a `messages.<locale>.yaml` file
into your app's `translations/` directory with only the keys you want to
change — Symfony will merge the rest from the central package.

## Testing

```bash
vendor/bin/phpunit
```

## License

MIT
