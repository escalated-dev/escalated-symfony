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
| Two-factor authentication | not available yet | |
| Single sign-on | not available yet | |

This bundle does not enforce two-factor authentication or SSO yet, so
`features.two_factor` and `features.sso` are always false and no screen for
either is shown.

```yaml
# config/packages/escalated.yaml
escalated:
    access:
        roles_permissions: true   # or leave unset and set ESCALATED_ROLES_PERMISSIONS=true
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

### Shared props

Every page's shared data (`_escalated_shared`) has `features.roles_permissions`,
`features.two_factor` and `features.sso` (booleans) and `permissions`: the
user's slugs while roles are on, `['*']` for staff while off, `[]` for everyone
else.
