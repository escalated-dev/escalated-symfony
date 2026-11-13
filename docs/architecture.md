# Architecture

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
