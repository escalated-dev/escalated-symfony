# Customization

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
