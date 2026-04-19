# Changelog

All notable changes to `escalated-symfony` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
