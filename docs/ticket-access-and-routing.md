# Ticket access and routing

All options are opt-in. Existing installations keep unrestricted agent visibility, no account resolution, and their existing SLA attachment behavior.

```yaml
# config/packages/escalated.yaml
escalated:
    user_class: App\Entity\User
    visibility: all                 # all | department
    accounts:
        enabled: false
        scope_agents: false
        resolver: null              # service implementing TicketAccountResolverInterface
    targeted_slas: false
    access_user_resolver: null      # optional TicketAccessUserResolverInterface service
```

Run `doctrine:migrations:migrate` before enabling options. `Version20261013000001` adds nullable, indexed `account_type` / `account_id` ticket columns, nullable SLA targets, and the `department_agent` membership table. It preserves ticket rows and existing SLA deadlines. Account identity comparisons are case-sensitive on SQLite, PostgreSQL (`C`) and MySQL (`utf8mb4_bin`). Rollback removes the new fields and memberships; turn the options off before rolling back.

## Department visibility

Set `visibility: department` and persist `new DepartmentAgent($department, $agentId)` memberships. Agent ids use the existing `ESCALATED_USER_KEY_TYPE` setting (`int` by default; `bigint`, `uuid`, and `string` are supported). The membership table belongs to the package database and has no foreign key or join to a host user table. Agent status still uses `AgentProfile`; admins use the existing `ROLE_ESCALATED_ADMIN` role.

Agents see tickets in their departments. Tickets with no department are hidden from restricted agents; intake should supply a department. Admins retain full access. An explicit department filter can only narrow the permitted set. Customer ownership and guest-token checks still apply. An authenticated agent using a widget guest token remains restricted by that agent's departments.

The request subscriber runs after the firewall and API bearer authenticator, before controllers and entity arguments. Bearer access uses the token owner, including when an admin session is present. An admin token ability does not make a non-admin owner exempt from visibility. Request completion and Symfony service reset restore the previous actor.

The Doctrine filter covers ticket list/search/count/report queries, ORM bulk updates/deletes, related records, both ends of ticket links, delayed actions and ticket custom-field values. The widget and API use the same filter. The raw mention inbox and read updates apply the same predicate. There is no separate bulk HTTP endpoint in this backend; host ORM bulk operations are filtered before writes.

`TicketRepository::find()` performs a filtered query rather than returning an unverified identity-map entry. Package service methods and ticket/attachment normalizers check preloaded entities. The flush guard checks original database visibility, current child parents, collection owners and assignee eligibility. Hosts can use the `ESCALATED_TICKET_VIEW`, `ESCALATED_TICKET_UPDATE`, `ESCALATED_TICKET_REPLY`, `ESCALATED_TICKET_ASSIGN`, and `ESCALATED_TICKET_CLOSE` voters on preloaded tickets.

Host code should use the package repository and services. Doctrine's raw `EntityManager::find()` can return an already-managed object, and native DBAL SQL bypasses ORM filters. For custom SQL, compose `TicketAccess::predicate(Ticket::class, $trustedAlias)` into the query. Use `AssignmentService` when assigning agents; bulk DQL bypasses entity lifecycle hooks. Do not share an entity manager concurrently across requests.

Console commands and workers without an actor have full access. `TicketAccess::runAs($user, $callback)` supplies a scoped actor for host jobs and restores it even after exceptions. `runAs(null, $callback)` is the explicit bypass for trusted internal work.

## Accounts

Implement `TicketAccountResolverInterface::resolve($kind, $type, $id)` and register its service id in `accounts.resolver`. It returns `['type' => 'Company', 'id' => 'company-uuid']` or `null`. Both non-empty values are required and limited to 191 bytes. The resolver receives `requester`, `subject`, or `agent` as its kind; host code owns loading and mapping those identities to a company.

With `accounts.enabled`, `TicketService::create()` resolves the requester before creation workflows run. Subject attachment/sync fills an empty account using an atomic compare-and-set within the link transaction. Existing accounts are never overwritten by later subjects. Splits inherit the original account before creation workflows. HTTP account fields are not mass-assigned. Trusted code can use `Ticket::setAccount()` to explicitly replace or clear both values together.

Set `accounts.scope_agents: true` to intersect department access with the agent's resolved account. Each agent has one account; a missing/incomplete pair denies that agent all tickets. Admins bypass both restrictions. Multi-company agent coverage should use department membership instead of this single-account option.

Ticket list filters accept `ticket_type` and a complete `account_type` / `account_id` pair. Incomplete account filters return no results.

## Rules and SLAs

Workflows, automations and escalation rules accept `ticket_type`, `account`, `account_type`, and `account_id` conditions. Prefer the complete `account` condition for routing a specific company:

```json
[
  {"field": "ticket_type", "operator": "equals", "value": "task"},
  {"field": "account", "operator": "equals", "value": {"type": "Company", "id": "company-uuid"}}
]
```

`equals` / `=` / `==` and `not_equals` / `!=` are supported. Incomplete account conditions never match, including with negation. Automation and escalation rules use database prefilters followed by exact comparisons, including for negated conditions. Automations also accept `department_id`.

With `targeted_slas: true`, ticket creation attaches the most specific active policy: department + type, department, type, then an untargeted default. Lowest id wins ties. Set targets with `SlaPolicy::setDepartment()` and `setTicketType()`. SLA processing must also be enabled. When targeting is off, automatic targeting stays off and explicit default attachment excludes targeted policies.

Changing department or ticket type preserves the current SLA and deadlines. Call `SlaService::attachDefaultPolicy($ticket)` to explicitly reselect; deadlines are calculated from the original ticket creation time, not the move time.

## Recipients and real-time delivery

Assignment, skill routing, chat routing, mentions and the flush guard check assignee eligibility. Host notification workers should call `TicketAccess::recipients($ticket, $ids, $excludeId)` immediately before sending. The older `FollowerRecipients::resolve()` only deduplicates ids and excludes the actor. User identities are reloaded through the host's Doctrine manager; custom identity stores can implement `TicketAccessUserResolverInterface` and return current roles for the requested id.

When restrictions are enabled, legacy broadcasters are suppressed. Implement `AuthorizedBroadcasterInterface::broadcastAuthorized($event, $canReceive)` and call `$canReceive($authenticatedUserId)` immediately before each delivery, including buffered or queued deliveries. `TicketBroadcastDelivery` uses the ticket id/reference and rechecks current membership and account access. Host publishers should use this service too. The callback authorizes authenticated recipients; guest real-time delivery requires a separate host guest-token transport. Widget HTTP access remains available.

## Separate databases and prefixes

Set `entity_manager` to the package's named Doctrine manager. Host user resolution uses the manager that maps `user_class`; visibility subqueries stay entirely on the package connection. Set `table_prefix` consistently with the existing package tables. The migration factory passes it to every bundle migration, so a fresh `doctrine:migrations:migrate` creates the prefixed tables, and ORM metadata plus the workflow/mention/skill-routing helpers honor it.

Older historical migrations still use their original table names. Hosts with an existing custom-prefix schema can apply the new migration directly or generate their own Doctrine migration; do not replay the old default-prefix history against that schema. Arbitrary host SQL and other legacy raw helpers remain the host's responsibility.
