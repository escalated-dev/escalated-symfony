# Cursor task: skills-management parity for escalated-symfony (greenfield)

Self-contained brief. Read this fully before doing anything.

## Goal
Greenfield: implement the canonical Skills-management contract end-to-end on this plugin.

**Tracking issue:** https://github.com/escalated-dev/escalated-symfony/issues/56
**Canonical contract:** https://github.com/escalated-dev/escalated-developer-context/blob/main/domain-model/skills-management.md
**ADR:** https://github.com/escalated-dev/escalated-developer-context/blob/main/decisions/2026-05-13-skills-routing-explicit-mapping.md
**Reference impl:** https://github.com/escalated-dev/escalated-nestjs/pull/45 and https://github.com/escalated-dev/escalated-laravel/pull/95

## Current state
No skills code today. Symfony 6/7 backend, Doctrine ORM. Reference controllers live in `src/Controller/Admin/`. Existing migrations live in `migrations/` (Doctrine migrations).

## Deliverables

1. **Doctrine migrations** (`migrations/Version*.php`): three new tables — `escalated_skills`, `escalated_agent_skills`, `escalated_skill_routing_tags`, `escalated_skill_routing_departments` — matching the contract. If `escalated_skills` already exists from earlier work, only add what's missing.

2. **Doctrine entities** (`src/Entity/` or wherever existing entities live — check `src/`): `Skill`, `AgentSkill`, `SkillRoutingTag`, `SkillRoutingDepartment` with the relations from the contract.

3. **Repository classes** for each entity.

4. **Form types** or DTOs for create/update — match how other admin controllers validate.

5. **Controller** (`src/Controller/Admin/SkillController.php` — match the existing AutomationController / DepartmentController shape): 6 actions, JSON response per the contract. Wrap multi-table writes in a `$entityManager->wrapInTransaction(...)`.

6. **Routes** (`config/routes/escalated.yaml` or wherever): named `escalated.admin.skills.*`.

7. **Sidebar wire-up**: confirm the admin sidebar surfaces skills (look at AutomationController patterns).

8. **Tests** (`tests/`): controller integration + service unit tests.

## Process

1. `git checkout -b feat/admin-skills-management`.
2. Read the contract + reference impl.
3. Implement: migrations → entities → repositories → form types → controller → routes → tests.
4. Run: `composer install` if needed, `vendor/bin/phpunit`, `vendor/bin/php-cs-fixer fix`.
5. Commit logically, reference #56.
6. Push, open PR titled `feat(skills): admin skills management parity (#56)`.

## Constraints
- Symfony 6/7 idioms; PHP 8.x.
- snake_case at the wire.
- Stop after pushing. The PROMPT file is untracked — don't include it in the PR.
