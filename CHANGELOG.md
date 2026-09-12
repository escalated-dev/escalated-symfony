# Changelog

All notable changes to `escalated-symfony` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
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
