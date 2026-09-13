# Changelog

All notable changes to `escalated-symfony` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
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
