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

[![Views](https://hits.sh/github.com/escalated-dev/escalated-symfony.svg?style=flat&label=views&color=007ec6)](https://hits.sh/github.com/escalated-dev/escalated-symfony/)

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

### Import the routes

Create `config/routes/escalated.yaml`:

```yaml
escalated:
    resource: '@EscalatedBundle/config/routes.yaml'
```

The bundle cannot register routes from its container extension, so this import
is required. The file hands off to the bundle's `escalated` route loader, which
applies your configuration when the router loads it: the JSON API is always
registered; the customer, agent, admin and widget UI only when `ui_enabled` is
true; the newsletter routes only when `enable_newsletters` is true as well.
Every route is mounted under `route_prefix`.

### Inbound email (optional)

Replies and new tickets can arrive by email through your mail provider's inbound webhook. Set a shared secret:

```yaml
# config/packages/escalated.yaml
escalated:
    inbound_secret: '%env(ESCALATED_INBOUND_SECRET)%'
```

Then point the provider at:

```
POST {route_prefix}/escalated/webhook/email/inbound?adapter=postmark|mailgun|ses
X-Escalated-Inbound-Secret: <the secret>
```

The adapter can also be sent as an `X-Escalated-Adapter` header.

- With no secret configured, the webhook refuses every request.
- The same secret signs the Reply-To address on outbound mail, so a reply is matched back to its ticket.
- To support another provider, implement `Escalated\Symfony\Mail\Inbound\InboundEmailParser`. Autoconfigured services implementing it are registered automatically.

### Outbound webhooks

Webhook URLs must use `http` or `https` and point to a public address. This is checked when a webhook is saved and again on every delivery. Private, loopback, link-local and reserved addresses are refused, deliveries never follow redirects, and each request connects to the address that passed the check. To deliver to receivers on your own network on purpose:

```yaml
escalated:
    webhooks:
        allow_private_networks: true
```

### Schedule the background commands

Several features run from console commands rather than on a request. Run them from cron, Symfony Scheduler or your platform's job runner:

```cron
* * * * *  php bin/console escalated:check-sla-breaches      # mark overdue tickets, fire sla.breached
* * * * *  php bin/console escalated:process-delayed-actions # run delayed workflow actions that are due
* * * * *  php bin/console escalated:wake-snoozed-tickets    # reopen tickets whose snooze expired
*/5 * * * * php bin/console escalated:automations:run        # time-based automations
*/5 * * * * php bin/console escalated:escalations:run        # escalation rules
*/5 * * * * php bin/console escalated:chat:close-idle        # close idle live chats
* * * * *  php bin/console escalated:newsletters:dispatch    # only with enable_newsletters
```

Without the first two, SLA breaches are never recorded and delayed workflow actions never run.

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

The bundle registers its own migrations, in the `Escalated\Symfony\Migrations` namespace, whenever DoctrineMigrationsBundle is enabled. You do not need a `migrations_paths` entry for them. They are written against Doctrine's schema API and run on MySQL/MariaDB, PostgreSQL and SQLite. Afterwards `php bin/console doctrine:schema:validate` reports the Escalated tables in sync with the entities.

#### Upgrading from 0.2.x or earlier

Earlier releases shipped one migration as `Escalated\Symfony\Migrations\Version20260406000001` and the rest as `DoctrineMigrations\Version…`. Doctrine could discover only one of the two groups, and most of them were MySQL-only SQL. They all now share the bundle namespace. Find your situation below:

- **You ran them as `DoctrineMigrations\Version…`** (by mapping that namespace to the bundle's `migrations/` directory, or by copying the files into your own). Nothing to do. On the next `migrate`, each of those migrations sees its old name in `doctrine_migration_versions`, records the new name without running again, and leaves the old row alone. Doctrine will list the old rows as "previously executed migrations that are not registered". Once the new names show as executed, you can remove the old rows with `php bin/console doctrine:migrations:version 'DoctrineMigrations\Version20260407000001' --delete` (one call per version). If you copied the files into your own migrations directory, delete the copies too.
- **You renamed Doctrine's metadata table** (`doctrine_migrations.storage.table_storage.table_name`). The automatic step reads the default table name. Record the versions you had already run under their new names instead: `php bin/console doctrine:migrations:version 'Escalated\Symfony\Migrations\Version20260407000001' --add`, and so on.
- **You created the tables some other way** (`doctrine:schema:update`, as the Docker demo used to). Record each already-applied bundle version with `doctrine:migrations:version 'Escalated\Symfony\Migrations\Version…' --add` before running `migrate`.
- **You mapped `Escalated\Symfony\Migrations` to `vendor/escalated-dev/escalated-symfony/migrations` yourself.** You can remove that entry; it is now redundant.

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

Symfony voters control access:

- `ESCALATED_AGENT` -- Granted when the user has an `AgentProfile` record. An API token user also needs the `agent`, `admin` or `*` ability, and the token's owner must still have an `AgentProfile`.
- `ESCALATED_ADMIN` -- Granted when the user has the `ROLE_ESCALATED_ADMIN` role
- `ESCALATED_TICKET_REQUESTER` -- Granted on a `Ticket` when the user is its requester

#### JSON API authentication

Every `/api/v1` route except the knowledge base requires an authenticated principal. Requests without one get `401 {"message": "Unauthenticated."}`. Two kinds of principal are accepted:

- **A bearer token:** `Authorization: Bearer <token>`, created from the admin API-token screen. It authenticates only the request that carries it and is never written into the session.
- **A signed-in session:** the user your firewall already authenticated.

The ticket endpoints (list, show, create, update, status, custom actions, subjects) then require `ESCALATED_AGENT` (`403` otherwise). Rating a ticket is allowed for agents and for the ticket's requester. The knowledge-base endpoints follow the knowledge-base settings: public by default.

Both listeners run after the host firewall, so the API works behind a stateful `main` firewall with no extra security configuration.

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

## Newsletters (optional, disabled by default)

Admin-only broadcast feature for sending Markdown emails to contacts. Off by default — pass `enabled: true` to `NewsletterDispatcher` to turn it on.

Register the newsletter Twig namespace in your bundle config:

```yaml
twig:
  paths:
    '%kernel.project_dir%/vendor/escalated-dev/escalated-symfony/templates': EscalatedNewsletter
```

Wire the services in `services.yaml`:

```yaml
Escalated\Symfony\Service\Newsletter\NewsletterRenderer:
  arguments:
    $twig: '@twig'
    $baseUrl: '%env(APP_URL)%'
    $defaultTheme: 'default'
    $trackingEnabled: true
    $markdownToHtml: !service { class: 'Closure', factory: 'fn (string $md) => Symfony\Component\String\u($md)->toString() }
    # ^^^ swap for league/commonmark, parsedown, etc. in your app

Escalated\Symfony\Service\Newsletter\NewsletterDispatcher:
  arguments:
    $enabled: true
    $batchSize: 50
    # …
```

Then run the dispatcher on a cron (Symfony Messenger / cron job / Scheduler):

```php
$container->get(NewsletterDispatcher::class)->dispatchBatch();
```

Custom themes go in `templates/newsletter_themes/<slug>.html.twig`.

## Testing

```bash
vendor/bin/phpunit
```

## Database connection

By default Escalated's entities resolve your application's **default** Doctrine
entity manager. Name a different one to keep the support tables somewhere else —
a schema shared with a legacy system, a multi-tenant split, a separate reporting
store, or simply out of your primary database.

Declare the connection and manager as you normally would, mapping Escalated's
entity namespace to it:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        connections:
            support:
                url: '%env(resolve:SUPPORT_DATABASE_URL)%'
    orm:
        entity_managers:
            support:
                connection: support
                mappings:
                    Escalated:
                        type: attribute
                        dir: '%kernel.project_dir%/vendor/escalated-dev/escalated-symfony/src/Entity'
                        prefix: 'Escalated\Symfony\Entity'

# config/packages/escalated.yaml
escalated:
    entity_manager: support
```

Leave `entity_manager` unset for the default manager — the historical behaviour,
and what almost every host wants.

### Why the bundle needs the setting at all

Repositories would have followed your mapping on their own:
`ServiceEntityRepository` resolves through `ManagerRegistry::getManagerForClass()`,
which routes by the entity-to-manager mapping above.

The problem is everything else. Sixty-two services in this bundle autowire
`EntityManagerInterface`, and that resolves the **default** manager regardless of
mapping — so without this setting they would all keep writing to your primary
database, silently and without error. `escalated.entity_manager` is aliased from
the option and bound by type in the bundle's `services.yaml`, so every one of
them follows your choice.

**Your user entity does not move.** It belongs to your application and stays on
whichever manager maps it. Escalated stores host user ids as plain unconstrained
columns precisely so the two can live on different connections.

Setting this on an existing install does not move existing data. Migrate the
tables and copy the rows across first.

## License

MIT
