# Changelog

## [Unreleased]

### Added
- Workflow admin, per escalated-developer-context `domain-model/workflow-admin-contract.md`: `{route_prefix}/admin/workflows` routes (`escalated.admin.workflows.index`, `create`, `store`, `edit`, `update`, `destroy`, `toggle`, `reorder`, `logs`) rendering the shared `Escalated/Admin/Workflows/Index`, `Form` and `Logs` pages. Create and update accept the shared builder's body (top-level `trigger_event`, conditions `{all|any: [...]}`, actions `[{type, value}]`), store a legacy trigger name as its canonical name, and on a validation failure redirect back with the errors flashed under `errors` (a request without `X-Inertia` that asks for JSON gets a 422). The Form page gets `trigger_events`, `action_types` and `operators` listing exactly what the engine fires, runs and evaluates.
- Workflow conditions on `ticket_type`, `channel`, `tags`, `hours_since_created` and `hours_since_updated`.

### Fixed
- Workflow actions `add_tag`, `remove_tag`, `set_department` and `insert_canned_reply` did nothing: a workflow built with the core action catalog ran, logged success and left the ticket unchanged. They now act (`add_tag` creates a missing tag; `insert_canned_reply` posts an authorless public reply that does not re-trigger reply workflows). `delay` now holds back the actions after it until `escalated:process-delayed-actions` runs them; before, it was skipped and the later actions ran at once. Unknown action types are logged as `skipped` instead of `executed`, and failed actions carry their error in the log.
- `WorkflowEngine::ACTION_TYPES` is deprecated: it listed `send_webhook` and `send_notification`, which the engine never ran. Use `OFFERED_ACTIONS` or `HANDLED_ACTIONS`.

### Changed
- Webhooks and workflows use the shared event vocabulary (escalated-developer-context `decisions/2026-10-21-event-vocabulary.md`), defined once in `Escalated\Symfony\Event\EventNames`:
  - Tags are dispatched as `ticket.tag_added` and `ticket.tag_removed` (was `ticket.tagged` with an `action` context). A `ticket.tag_added` or `ticket.tag_removed` workflow now runs; before, only `ticket.tagged` did.
  - Workflows also fire on `ticket.resolved`, `ticket.closed`, `ticket.escalated` and `ticket.unassigned`, the events webhooks already received.
  - Live chat emits `chat.started`, `chat.assigned`, `chat.message` and `chat.ended` to webhooks and workflows. The payload carries the ticket and `chat_session_id`, never the visitor's chat token. Realtime broadcast names are unchanged.
  - The webhook admin offers exactly the canonical names the bundle emits, and stores a legacy name posted by an older form as its canonical name.
- Legacy names keep working on read: a webhook subscribed to `internal_note.added`, `ticket.tagged`, `ticket.replied`, `chat.session_started` and the like receives the canonical event, and a workflow saved as `ticket.replied`, `ticket.tagged` or `chat.session_started` still fires.

### Upgrade notes
- **Behaviour change:** a workflow saved as `ticket.tagged` used to run when tags were added *and* removed. It is now read as `ticket.tag_added`, as the ADR says, and runs on additions only. Re-save it as `ticket.tag_removed` (or add a second workflow) if it should also run on removals.
- Host listeners for `TicketWorkflowEvent` that matched `triggerName === 'ticket.tagged'` should match `ticket.tag_added` / `ticket.tag_removed`, or use `EventNames::forEvent($event)`.
- `ChatSessionService` takes an optional event dispatcher as its last constructor argument; autowired hosts need no change.

## [0.3.2] - 2026-10-20

### Changed
- The bundled widget is now `@escalated-dev/escalated` 0.12.3. It keeps its script settings when `embed.js` is placed in the page `<head>`, and Enter no longer picks a stale mention suggestion.

### Fixed
- Agent live chat accept, message and end no longer answer 500. The agent's primary key is resolved with `UserKeyResolver`, and chat sessions and routing keep UUID and string agent ids.
- Personal macros and canned responses list and apply for agents whose identifier is an email, and macro replies are authored by the real agent instead of user 0. Macros, canned responses, mentions, 2FA, agent broadcast channels and skill assignments accept `int|string` user keys.
- A custom `table_prefix` now applies to every bundle migration, including generated index and foreign key names, so a fresh migrate and `doctrine:schema:validate` work with a prefix.
- The widget no longer shows a requester's email reply as written by support. `is_agent` is true only for replies an agent wrote.

## [0.3.1] - 2026-10-19

### Changed
- The bundled widget is now `@escalated-dev/escalated` 0.12.2. It hides its Check Status tab when `/config` returns `status_lookup_enabled: false`.

- Add opt-in department/account visibility across ticket queries, services, policies, reports, widget and API access, with guarded assignments and delivery hooks.
- Add nullable ticket account identities, resolver hooks, account/type routing conditions, and opt-in targeted SLAs.
- Add cross-database regression coverage for visibility, custom prefixes, separate host databases, migration upgrade/rollback and UUID memberships.
- Correct the SLA report query field names.
- Keep UUID/ULID agent ids in escalation-rule, macro, automation and workflow assign actions; they were cast to int and stored as 0 or the id's leading digits (#97).
- Add cross-origin widget embedding: `GET {route_prefix}/widget/api/embed.js` serves the standalone widget bundle (`@escalated-dev/escalated` 0.12.1), and the widget API answers CORS preflights and grants CORS only to origins on the allow list (`WidgetSettings` `$allowedOrigins`, e.g. from `ESCALATED_WIDGET_ALLOWED_ORIGINS`, plus the new admin setting `widget_allowed_origins`; `https://*.example.com` wildcards). Allowed cross-site requests always run as a guest. See `docs/embedding-the-widget.md`.
- Widget tickets and live chats store sanitised host parameters sent as `metadata` (tickets keep `source: widget`; chats store them in `chat_metadata`), with `embedded_on` set by the server. `/config` returns `accepts_metadata: true`. Chat start no longer passes the raw request body to `ChatSessionService::startSession()`.
- Align the widget API with the shared widget (`@escalated-dev/escalated` 0.12.1), keeping every existing route and field: `/config` adds top-level `color`, `position`, `greeting`, `departments`, `kb_enabled`, `guest_tickets_enabled` and `status_lookup_enabled: false` next to `data` (no `guest_verification_required`, since there is no `/verification` or `/lookup`); new `GET /articles?q=` and `GET /articles/{slug}`; `/tickets` and `/chat/start` accept `name`/`email` as well as `guest_name`/`guest_email`, and `department_id`; ticket creation returns top-level `reference` and `guest_access_token`; `/tickets/{reference}` accepts `Authorization: Bearer` and returns the status at the top level; `/chat/availability` returns top-level `available`; chat start returns `id` (the random guest token), and `/chat/{id}/messages` (GET, POST), `/typing`, `/end` and `/rate` address the chat by that token. Errors carry `message` next to `error`.
- Fix the original `/chat/sessions/{reference}/messages` and `/end` routes, which always answered 500 (the controller looked up `doctrine` from its service locator).
- **Behaviour change (security):** an empty `WidgetSettings` `$allowedOrigins` list used to allow every site, and the widget routes echoed any request `Origin` back as `Access-Control-Allow-Origin` without checking it. An empty list now allows no other site, unlisted origins get normal answers without CORS headers, and `/config` no longer answers 403 for an unlisted `Origin`. Hosts that embed the widget on another site must list that site. `WidgetSettings::isOriginAllowed()` follows the same rules.

All notable changes to `escalated-symfony` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


### Security
- Rate-limit `POST {route_prefix}/widget/api/chat/start`. It creates a ticket and chat session per request but had no limit; it now shares the guest ticket limit (`escalated.guest_rate_limit.tickets_per_minute`, default 5 per client IP per minute) with `POST /widget/api/tickets`, checked before validation, and answers 429 with `Retry-After`. Allowed embedding origins still get CORS headers on the 429.

## [0.3.0] - 2026-10-08

This release changes how inbound email is matched to tickets. Read
**Upgrading** before deploying.

### Upgrading
- **Inbound replies come only from the ticket's requester, through the signed
  Reply-To address.** `In-Reply-To` / `References` headers and subject
  references no longer link an inbound email to a ticket; only the signed
  Reply-To address that outbound notifications carry does. A matched email is
  a reply only when `From` is the ticket's guest email or the requester user's
  email. Every other email, including an agent answering a notification by
  email, opens a new ticket for its sender. Agents should reply in the app.
- `escalated.inbound_secret` must be set for the inbound webhook to work at all
  (it is also the key that signs Reply-To addresses). Replies to mail sent
  before a secret was configured carry no signed address and open new tickets.
- **Guest widget endpoints are rate-limited per client IP** (5 tickets and 10
  replies per minute by default). Configure `escalated.guest_rate_limit`
  (`enabled`, `tickets_per_minute`, `replies_per_minute`, `cache_pool`). Set
  `framework.trusted_proxies` behind a reverse proxy, or every guest shares the
  proxy's budget, and name a shared PSR-6 cache pool when running more than one
  server.

### Added
- **Per-IP rate limit on guest widget tickets and replies.** The widget ticket
  endpoint allows 5 tickets and the widget reply endpoint 10 replies per client
  IP per minute, with separate counters. Over the limit the request gets `429`
  with `Retry-After`. Replies are counted before the guest token is checked, so
  wrong-token guesses count too.
  - Configure under `escalated.guest_rate_limit`: `enabled`,
    `tickets_per_minute`, `replies_per_minute`, and `cache_pool` (a PSR-6 pool
    service id, default `cache.app`; name a shared pool on multiple servers).
  - Behind a reverse proxy, set `framework.trusted_proxies`, or every guest
    shares the proxy's address.

### Security
- **Inbound email replies were accepted from any sender.** An email that
  matched a ticket by `In-Reply-To` / `References` or a subject reference was
  added as a reply whoever sent it.
  - Only the signed Reply-To address now links an inbound email to a ticket.
  - A matched email is a reply only when `From` is the ticket's guest email or
    the requester user's email, and a requester user is recorded as the
    reply's author. Any other sender gets a new ticket of their own.

## [0.2.1] - 2026-09-13

### Security
- **Webhooks could target the server's own network.** A webhook URL only had
  to be syntactically valid. An admin, or anyone holding an admin session, could
  point it at a local service, a private address or the cloud metadata endpoint,
  including over non-HTTP schemes curl supports. The server then sent a request
  there on every ticket event and stored the response in the delivery log.
  - URLs must now use `http`/`https` and resolve to public addresses. This is
    checked on save and on every delivery.
  - Deliveries connect only to the address that passed the check and never
    follow redirects.
  - `escalated.webhooks.allow_private_networks` re-enables internal receivers
    deliberately.

### Fixed
- **Required packages were not declared.** The bundle uses these packages at
  runtime but did not require them:
  - `symfony/serializer` (API controllers and normalizers)
  - `symfony/mailer` and `twig/twig` with `symfony/twig-bundle` (newsletters)
  - `symfony/yaml` (its own service and routing configuration)

  A host that had not installed them could not build its container. They are
  now in `require`.
- **SLA breaches and delayed workflow actions never ran.**
  `SlaService::checkBreaches()` and `WorkflowEngine::processDelayedActions()` had
  no caller. Two new console commands run them, `escalated:check-sla-breaches`
  and `escalated:process-delayed-actions`; schedule both every minute (see the
  README).
- **Workflows saved against several triggers never ran.**
  - Replies were dispatched as `ticket.replied`, while the trigger list and the
    workflow contract call the event `reply.created`. Replies now dispatch
    `reply.created`, and workflows saved as `ticket.replied` still run.
  - `ticket.reopened` and `ticket.department_changed` are now dispatched: on
    reopen, and when an escalation rule moves a ticket.
  - `reply.agent_reply` and `sla.warning` had no code path and are no longer
    offered.
- **The webhook admin offered events nothing sent.** `ticket.unassigned`,
  `note.created`, `ticket.department_changed` and `ticket.tag_removed` are now
  emitted. `sla.warning` is no longer offered.
- **On PostgreSQL, workflows never ran and new tickets were lost.**
  `WorkflowEngine` and `EmailChannelService` compared boolean columns to integer
  literals in raw SQL (`is_active = 1`, `executed = 0`, `SET executed = 1`,
  `SET is_default = 0`), which PostgreSQL rejects. The trigger subscriber
  swallowed the error, so no workflow ran and delayed actions were never picked
  up. `ticket.created` runs inside the flush that inserts the ticket, where the
  failed statement aborted the transaction, so the ticket itself was rolled back.
  Changing the default email channel also failed. The statements now bind
  booleans with their DBAL types, and CI runs every kernel test on PostgreSQL.
- **Inbound email could not be received.**
  - No routing file imported the webhook controller.
  - Nothing tagged the Postmark, Mailgun and SES parsers, so every adapter was "unknown".
  - Nothing supplied the shared secret, so the controller refused every request.

  The webhook is now registered under `route_prefix`, even with the UI disabled.
  The parsers are tagged through their interface. A new `inbound_secret` option
  feeds the webhook check and Reply-To signing; leaving it empty keeps the
  webhook disabled.
- **The default configuration broke the container build.** With `ui_enabled: true`
  the bundle imported `config/routes.yaml` through the service-container loader,
  which failed with `There is no extension able to load the configuration for
  "escalated_customer"`. Routes now come from an `escalated` route loader behind
  `@EscalatedBundle/config/routes.yaml`: the API always, the UI only when
  `ui_enabled` is true, the newsletter routes only when `enable_newsletters` is
  also true, all under `route_prefix`. Hosts must import that file (README,
  "Import the routes"); hosts that already import it need no change.
- **The JSON API answered requests with no credentials.** Without an
  `Authorization` header the token listener stepped aside and no ticket endpoint
  checked the caller: the ticket list returned every ticket, and create, update,
  status, rating and subject changes went through anonymously. Every `/api/v1`
  route except the knowledge base now requires a bearer token or a signed-in
  session (`401` otherwise), and the ticket endpoints require agent access
  (`403`). Rating is also open to the ticket's requester.
- **Valid API tokens never authenticated.** The token listener ran before the
  host firewall, which reset the token storage from the session and discarded
  the token. It now runs after the firewall, and a bearer token is no longer
  written into the session.
- **Migrations could not build the schema.**
  - Only one of the shipped migrations was discoverable: one used
    `Escalated\Symfony\Migrations` and the rest `DoctrineMigrations`, and a
    migrations path maps a single namespace.
  - Most of them were raw MySQL (`AUTO_INCREMENT`, inline `COMMENT`, `ENGINE`),
    which SQLite and PostgreSQL reject.

  All migrations now live in `Escalated\Symfony\Migrations`, the bundle
  registers them itself, and they use Doctrine's schema API. An install that ran
  them as `DoctrineMigrations\Version…` records the new names without running
  anything again (see the README's upgrade notes).
- **Three features had no tables.** `escalated_automations`, `escalated_macros`
  and `escalated_saved_views` were mapped but never migrated; a new migration
  creates them where they are missing.
- **Workflow runs could not be logged.** `WorkflowEngine` inserted a `status`
  column the `WorkflowLog` entity does not have. The engine now writes
  `conditions_matched`, `started_at` and `completed_at`, and two migrations move
  existing rows over and add the log table's foreign keys.
- **The entities and the migrated schema disagreed.** Names, defaults and
  constraints differed, so `doctrine:schema:validate` failed on every install.
  - Entities now declare the index names, column defaults and delete rules the
    migrations create.
  - Chat routing rules get their department foreign key, and chat sessions are
    unique per ticket.
  - A schema listener keeps the newsletter tables' foreign keys, which cascade
    deletes, in Doctrine's view of the schema.
  - `doctrine:schema:validate` now passes after migrating an empty SQLite,
    PostgreSQL or MySQL database, and CI checks all three.
- **Four screens rendered blank.** The bundle asked for page names that
  `@escalated-dev/escalated` does not ship:

  | rendered | the frontend ships |
  |---|---|
  | `Escalated/Admin/Settings/Index` | `Escalated/Admin/Settings` |
  | `Escalated/Agent/Tickets/Index` | `Escalated/Agent/TicketIndex` |
  | `Escalated/Agent/Tickets/Show` | `Escalated/Agent/TicketShow` |
  | `Escalated/Admin/CannedResponses/Form` | *(no such page — the list edits inline)* |

  Inertia resolves a page name with nothing behind it to nothing, so each
  returned 200 and rendered an empty panel.

  The canned-response `/new` and `/{id}/edit` routes now redirect to the list,
  which is where the form actually is: the frontend creates and edits inline and
  has no separate form page, as the Laravel package already reflected.

### Added
- **Kernel test harness** (`tests/Kernel/`) that boots the bundle in a minimal
  host with its real configuration, services and routes, so wiring faults fail a
  test instead of an install.
- **`tests/PageNameParityTest.php`**, asserting every page name this bundle
  renders resolves to a component. The comparison runs against the manifest the
  frontend publishes, vendored at `tests/Fixtures/escalated-pages.json` — no
  single repo's tests can see this on their own, because a controller test
  asserts a status and the frontend never hears the name.

## [0.2.0] - 2026-09-12

### Added
- **Configurable database connection.** `escalated.entity_manager` names the Doctrine entity manager Escalated's own entities live on. Null uses the default manager, which is the historical behaviour and leaves an unconfigured host unchanged.

  Repositories already followed the host's mapping — `ServiceEntityRepository` resolves through `ManagerRegistry::getManagerForClass()`. The gap was everywhere else: sixty-two services in this bundle autowire `EntityManagerInterface`, which resolves the *default* manager regardless of mapping, so they would all have kept writing to the primary database silently and without error. The option is aliased to `escalated.entity_manager` and bound **by type** in the bundle's `services.yaml`, so every one of them follows the host's choice with no signature change — and a service added later cannot reach the default manager by accident.

  Your user entity is deliberately not moved: it belongs to the host and stays on whichever manager maps it.

### Added

- Central translations are now sourced from the `escalated-dev/locale`
  Composer package. The bundle prepends the package's `translations/`
  directory onto `framework.translator.paths`; the plugin-local
  `translations/` directory and any host app `translations/` continue to
  override individual keys.

## [0.1.0] - 2026-04-18

Initial tagged release.

### Added

- Full Symfony bundle: `Ticket`, `Reply`, `Department`, `Tag`, `SlaPolicy`, `AgentProfile`, `EscalationRule`, `CannedResponse`, `Macro`, `ChatSession`, `Workflow` etc. as Doctrine entities; controllers under `Customer`, `Agent`, `Admin`, `Api`, `Widget`; voters; mailers; broadcasting; saved views; SLA engine; chat routing; import service.
- Docker dev/demo environment under `docker/` (excluded from the Composer dist). `docker compose up --build` boots a Postgres-backed Symfony 7 host with the bundle registered, Doctrine migrations + fixtures, and a Twig `/demo` click-to-login picker. (#23)

### Fixed

- **Symfony 7 / Doctrine 3 compatibility** (#22):
  - `EnsureAdminVoter` / `EnsureAgentVoter` updated to match Symfony 7's `voteOnAttribute(string, mixed, TokenInterface, ?Vote $vote = null)` signature; previously crashed with `Declaration ... must be compatible with Voter::voteOnAttribute(...)`.
  - `EscalatedBundle::configure()` now declares the configuration tree via the existing `Configuration` class. Previously every `escalated:` option in user config was rejected as `Unrecognized options`.
  - `config/services.yaml` typo'd two service IDs (`EscalatedSymfonyCommandWakeSnoozedTicketsCommand`, `EscalatedSymfonySecurityKnowledgeBaseGuard`) — namespace separators stripped — failing container compilation. Fixed and added a wildcard `Escalated\Symfony\:` resource autoregistration so newly added services are picked up automatically.
- **`EnsureAgentVoter` integer PK lookup** (#25, fixes #24) — voter previously called `findOneBy(['userId' => $user->getUserIdentifier()])`, querying an `INTEGER` column with the user's email and hitting `SQLSTATE[22P02] Invalid text representation` on Postgres. Now resolves the user's PK via Doctrine metadata.
