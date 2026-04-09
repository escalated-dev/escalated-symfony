<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <a href="README.it.md">Italiano</a> •
  <a href="README.ja.md">日本語</a> •
  <a href="README.ko.md">한국어</a> •
  <a href="README.nl.md">Nederlands</a> •
  <a href="README.pl.md">Polski</a> •
  <a href="README.pt-BR.md">Português (BR)</a> •
  <a href="README.ru.md">Русский</a> •
  <b>Türkçe</b> •
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for Symfony

An embeddable support ticket system for Symfony applications. Drop-in helpdesk with tickets, replies, departments, tags, SLA policies, and role-based access control.

## Gereksinimler

- PHP 8.2+
- Symfony 6.4 or 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## Kurulum

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

## Özellikler

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

### Services

| Service | Description |
|---|---|
| `TicketService` | Create, update, transition, reply to tickets |
| `AssignmentService` | Assign/unassign agents, check workload |
| `SlaService` | Attach SLA policies, check for breaches |

### Controllers

Routes are organized into four groups, all under the configured `route_prefix`:

- **Customer** (`/customer/tickets`) -- Ticket CRUD for authenticated end-users
- **Agent** (`/agent`) -- Dashboard and ticket management for support agents
- **Admin** (`/admin`) -- Full management of tickets, departments, tags, settings
- **API** (`/api/v1`) -- JSON REST API for external integrations

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

## Test

```bash
vendor/bin/phpunit
```

## Lisans

MIT
