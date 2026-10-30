# Access control: roles & permissions, two-factor, SSO

Escalated's roles & permissions, two-factor authentication and single sign-on
are **install-level switches**, off by default
([ADR 2026-10-29](https://github.com/escalated-dev/escalated-developer-context/blob/main/decisions/2026-10-29-configurable-access-features.md)).
While a switch is off its admin screens and routes answer 404 and nothing is
enforced; the `ESCALATED_ADMIN` / `ESCALATED_AGENT` gates decide who may do
what, exactly as before. While it is on, the feature is enforced.

The switches are read from configuration (or their env var) only. No admin
screen or stored setting changes them.

| Feature | Config key | Env var (used when the key is not set) |
|---|---|---|
| Roles & permissions | `escalated.access.roles_permissions` | `ESCALATED_ROLES_PERMISSIONS` |
| Two-factor authentication | `escalated.access.two_factor` | `ESCALATED_TWO_FACTOR` |
| Single sign-on | not available yet | |

This bundle does not enforce SSO yet, so `features.sso` is always false and no
screen for it is shown.

```yaml
# config/packages/escalated.yaml
escalated:
    access:
        roles_permissions: true   # or leave unset and set ESCALATED_ROLES_PERMISSIONS=true
        two_factor: true          # or leave unset and set ESCALATED_TWO_FACTOR=true
```

## Roles & permissions

### What is enforced

With the switch on, every admin, agent and staff API route needs a permission
**in addition to** the gate for that area. A user's permissions are the union
of their Escalated roles' permissions. Nothing else grants one: passing the
`ESCALATED_ADMIN` gate (`ROLE_ESCALATED_ADMIN`) gives no permissions by
itself. Give users who should have everything the `admin` role.

- The route to permission map is `src/Security/RoutePermissions.php` (a port of
  escalated-laravel's). A staff route missing from it is refused, and a test
  checks every `escalated.admin.*`, `escalated.agent.*` and `escalated.api.*`
  route (the mobile guest API and the public knowledge base API excepted) is in
  it.
- A status change needs `ticket.edit`, plus `ticket.close` when the new status
  is `resolved`, `closed` or `reopened`.
- Bulk actions need the permission of the action: `assign` needs
  `ticket.assign`, `delete` needs `ticket.delete`, the rest `ticket.edit`.
- Applying a macro needs `macro.view` and `ticket.edit`.
- API tokens act as their owner, so the staff API checks the owner's
  permissions as well as the token's abilities.
- The newsletter screens need `newsletters.manage` (and `newsletters.send` to
  send) as well as the admin gate.
- Plugin pages and endpoints are the plugin's to guard.

Failing a permission answers 403. Visitors who are not staff are refused by the
gates, as before.

The check is `Escalated\Symfony\EventSubscriber\RoutePermissionSubscriber`.
In your own code, `Escalated\Symfony\Security\UserPermissions::allows($user, 'ticket.close')`
is true while the switch is off and checks the user's roles while it is on.

### Seeded roles

`bin/console escalated:permissions:seed` creates the permissions and three
system roles (safe to run again):

| Role | Permissions |
|---|---|
| `admin` | all |
| `agent` | `ticket.*`, `reply.*`, `kb.view`, `report.view`, `macro.view`, `macro.create`, `tag.view`, `custom_field.view`, `audit.view` |
| `light_agent` | `ticket.view`, `reply.create`, `reply.create_internal`, `kb.view`, `macro.view`, `tag.view` |

Custom roles are made in **Admin > Roles** (`{route_prefix}/admin/roles`,
`role.view` / `role.manage`). System roles cannot be deleted.

### Giving users roles

- From the command line:
  `bin/console escalated:roles:assign you@example.com admin` (email or ID,
  then the role slug; `admin` when omitted).
- From the admin: `PUT {route_prefix}/admin/users/{user}/roles` with `role_ids`
  (needs `role.manage`; replaces the user's roles). You cannot remove your own
  `role.manage`. While the switch is on, the users page receives each user's
  `roles` (slugs) and a `roles` list of `{id, name, slug}`.

### Turning it on

1. Run the migrations (`Version20261030000001` adds `escalated_roles`,
   `escalated_permissions`, `escalated_role_permission` and
   `escalated_role_user`, with your `table_prefix`).
2. Seed the permissions: `bin/console escalated:permissions:seed`.
3. Give every staff user a role, starting with yourself:
   `bin/console escalated:roles:assign you@example.com admin`.
4. Set `escalated.access.roles_permissions: true` or
   `ESCALATED_ROLES_PERMISSIONS=true`, and clear the cache.

Staff without a role can reach nothing until they get one.

## Two-factor authentication

TOTP (RFC 6238: SHA-1, 6 digits, 30-second steps) through
[`pragmarx/google2fa`](https://github.com/antonioribeiro/google2fa), accepting
one step of clock drift either side.

### What is enforced

With the switch on:

- **Enrolment.** Every staff user (agents too, not only admins) can enrol at
  `{route_prefix}/admin/settings/two-factor` (`Admin/Settings/TwoFactor`,
  routes `escalated.admin.two-factor.*`; no permission is needed even with
  roles & permissions on). `setup` creates a pending secret and flashes
  `two_factor_setup: {qr_uri, recovery_codes}`; `confirm` takes the six-digit
  `code` and flashes `two_factor_confirmed: {recovery_codes}`; `disable`
  removes the enrolment. The secret and the eight recovery codes are stored
  encrypted (AES-256-GCM under a key derived from `kernel.secret`) and never
  sent in props. Changing `kernel.secret` makes existing enrolments unusable:
  reset them.
- **Challenge.** When a staff user with a confirmed enrolment opens any admin
  or agent route (or an admin plugin endpoint) and this session has not
  passed the challenge for them, they are redirected to
  `GET {route_prefix}/two-factor/challenge` (`Auth/TwoFactorChallenge`, props
  `action` and `error`). It posts `code` or `recovery_code` to
  `POST {route_prefix}/two-factor/challenge`. Success marks the session for
  that user, regenerates the session ID and returns to the page they asked
  for; failure flashes an error on the field sent (`errors.code` or
  `errors.recovery_code`; JSON clients get 422). JSON requests to a guarded
  route get `403 {"two_factor_required": true}` instead of a redirect.
- **No replay.** The time step of each accepted code is stored
  (`last_used_step`); that step and earlier ones are refused afterwards.
  Recovery codes work once.
- **Throttled.** The verify route allows 5 attempts per minute per user
  (`symfony/rate-limiter`, counters in the `guest_rate_limit.cache_pool`
  pool); the sixth answers 429 with `Retry-After`.
- **Every sign-in is challenged.** The host firewall's `LoginSuccessEvent` and
  `LogoutEvent` clear the mark (`EventSubscriber\TwoFactorSubscriber`), so a
  new login, including one through SSO, is challenged again. Escalated does
  not own your login form; the challenge sits between the session and the
  panel.
- **The token API is not challenged.** It is not session-based.

### Requiring it

`escalated.two_factor.required: true` (or `ESCALATED_TWO_FACTOR_REQUIRED=true`)
sends every staff user without an enrolment to the enrolment page, and they
can reach nothing else until they enrol. The `two_factor_required` admin
setting (saved from the two-factor page, or posted to
`{route_prefix}/admin/settings`) wins once saved. Both only apply while the
switch is on. `escalated.two_factor.issuer` (default `Escalated`) is the name
authenticator apps show.

### Lost devices

`bin/console escalated:two-factor:reset you@example.com` (email or ID) removes
the user's enrolment so they can set it up again.

### Turning it on

1. Run the migrations (`Version20261031000001` adds
   `escalated_two_factors.last_used_step` and stores recovery codes encrypted;
   it removes enrolment rows written by the old, unrouted `TwoFactorService`,
   which could never verify a real authenticator code).
2. Set `escalated.access.two_factor: true` or `ESCALATED_TWO_FACTOR=true`, and
   clear the cache.
3. Only after telling staff, require it if you want to.

### Shared props

Every page's shared data (`_escalated_shared`) has `features.roles_permissions`,
`features.two_factor` and `features.sso` (booleans), `permissions` (the
user's slugs while roles are on, `['*']` for staff while off, `[]` for everyone
else) and `two_factor_required` (whether the signed-in staff user must still
enrol).
