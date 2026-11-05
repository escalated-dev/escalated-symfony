# Agent panel data

The shared frontend's agent panel (`@escalated-dev/escalated`, `docs/agent-panel.md`) reads five things this bundle provides, as escalated-dev/escalated-developer-context ADR `2026-11-05-agent-panel-data` specifies. Run the migration `Version20261105000001` first: it adds `tickets.last_customer_activity_at`, `agent_profiles.chat_status` and the `ticket_reads`, `customer_presence` and `notifications` tables (with the configured table prefix).

## Agent status

- `PUT` or `POST /agent/status` (`escalated.agent.status`) with `{ "status": "online" | "away" | "offline" }` stores the agent's status on their agent profile (`chat_status`), the field chat routing reads: only `online` agents get new chats and count towards the widget's chat availability. A JSON client gets `{ "agent_status": "away" }`; an Inertia visit goes back. Anything else is a 422.
- Every staff page shares `escalated.agent_status` (default `offline`) and `escalated.agent_statuses` (`["online", "away", "offline"]`).

## Knowledge base search

- `GET /agent/kb/search?q=<2..200 chars>&limit=<1..20, default 10>` (`escalated.agent.kb.search`, permission `kb.view`) answers `{ "data": [{ id, title, excerpt, url, category: { id, name } | null, updated_at }] }`.
- Published articles only; title or body contains the term, case-insensitive, with `%` and `_` taken literally. Title matches come first, then the most recently updated. `url` is the article's absolute customer knowledge-base URL.
- 404 while the knowledge base is switched off.

## Unread tickets

- A ticket is unread for an agent when the customer was active on it after the agent last opened it, or when the agent marked it unread. Customer activity is the ticket's creation and every public reply from the customer side (the requester, a guest, an inbound email, or any user who is not staff); agents' replies, internal notes and replies written by a workflow are not. Existing tickets have no activity, so none turn unread on upgrade.
- Opening a ticket in the agent panel marks it read. `POST /agent/tickets/{reference}/read` and `/unread` (`escalated.agent.tickets.read` / `.unread`) answer `{ "is_unread": bool }`; the bulk endpoint takes `mark_read` and `mark_unread`.
- `is_unread` is on every `Agent/TicketIndex` row (one query for the page) and on `Agent/TicketShow`'s ticket (`false`). `inbox.unread` is `{ mine, unassigned, all }`, counted with `EXISTS` subqueries through the list's own filters and visibility, and cached with the rest of `inbox` for `escalated.agent_inbox.cache_seconds`.

## Customer presence

- Customer portal pages (`escalated.customer.*`) and the widget's guest ticket and chat endpoints record when the customer was last seen, at most once every 30 seconds. A guest chat is recorded on its ticket.
- Agent pages only: each `Agent/TicketIndex` row and `Agent/TicketShow`'s ticket carry `requester_online` (seen within `escalated.presence.online_window_seconds`, default 120) and `requester_last_seen_at`. Customer, guest and widget responses never include them.

## Notifications

- In-app notifications for agents: `ticket_assigned` (not when the agent assigned themselves), `ticket_reply` (customer-side public replies on tickets assigned to or followed by the agent), `mention` (an @mention of the agent in another agent's reply or note: `@{Full Name}` or `@name`, matched by name or email; the picker is `GET /admin/agents/search?q=`, `escalated.admin.agents.search`), `sla_breached` and `sla_warning` (the assignee; `sla.warning` comes from `escalated:check-sla-breaches`, for deadlines due within 30 minutes), and `chat_transferred` (the receiving agent). The person who caused the event is never notified.
- `GET /agent/notifications?page=&per_page=<1..50, default 20>&unread=1` (`escalated.agent.notifications.index`) answers `{ data: [{ id, type, title, body, ticket: { id, reference, subject } | null, url, created_at, read_at }], meta: { current_page, last_page, per_page, total } }`, newest first. A notification about a ticket the agent can no longer see is redacted: title "A ticket you can no longer view", `body`, `ticket` and `url` null.
- `POST /agent/notifications/{id}/read` answers `{ "unread_count": n }` (404 for another agent's notification); `POST /agent/notifications/read-all` answers `{ "unread_count": 0 }`.
- Staff pages share `escalated.notifications.unread_count`.
- With broadcasting on (`BroadcastSettings`), each notification is also broadcast as `notification.created` on the agent's channel `escalated.agents.{id}`.
- `escalated.agent_notifications: false` switches the feed off: no notifications are stored, the routes answer 404 and the shared count is left out.

## Configuration

```yaml
escalated:
    presence:
        online_window_seconds: 120
    agent_notifications: true
```
