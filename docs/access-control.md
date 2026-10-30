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
| Single sign-on | `escalated.access.sso` | `ESCALATED_SSO` |

```yaml
# config/packages/escalated.yaml
escalated:
    access:
        roles_permissions: true   # or leave unset and set ESCALATED_ROLES_PERMISSIONS=true
        two_factor: true          # or leave unset and set ESCALATED_TWO_FACTOR=true
        sso: true                 # or leave unset and set ESCALATED_SSO=true
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

## Single sign-on

With `escalated.access.sso` on, users sign in through the identity provider an
admin chooses in **Admin > Settings > SSO** (`{route_prefix}/admin/settings/sso`,
`Admin/Settings/SsoSettings`): `none`, `saml`, `oauth` or `jwt`, with that
provider's fields. Those settings stay runtime-editable and only take effect
while the switch is on. The page shows stored secrets (JWT secret, OAuth client
secret) only as `********`; posting the mask back keeps the stored value. It
also lists the read-only `endpoints` to give the identity provider:

| Endpoint | Route | Use |
|---|---|---|
| `GET {route_prefix}/sso/login` | `escalated.sso.login` | Start sign-in with the chosen provider (404 while it is `none`) |
| `GET {route_prefix}/sso/saml/metadata` | `escalated.sso.saml.metadata` | SP metadata XML; its URL is the default SP entity ID |
| `POST {route_prefix}/sso/saml/acs` | `escalated.sso.saml.acs` | SAML assertion consumer (HTTP-POST binding) |
| `GET {route_prefix}/sso/oauth/callback` | `escalated.sso.oauth.callback` | OAuth 2 / OIDC redirect URI |
| `GET\|POST {route_prefix}/sso/jwt/callback` | `escalated.sso.jwt.callback` | JWT hand-off (`jwt` parameter) |

A route of a provider other than the chosen one answers 404. The bundle puts no
CSRF check on these routes; the IdP's cross-site POSTs are authenticated by the
signed, single-use message.

### What is checked

- **SAML** (`onelogin/php-saml`, strict mode). Every response is refused while
  no IdP certificate is configured. The assertion must be signed by that
  certificate (a signed Response around an unsigned assertion is refused; keys
  in `KeyInfo` are never trusted), exactly one assertion, no
  `EncryptedAssertion`. `Issuer` must equal the IdP entity ID, `Audience` must
  include the SP entity ID, the Response `Destination` and the bearer
  `Recipient` must equal the ACS URL, and the validity windows must hold
  (php-saml allows 180 seconds of drift). `InResponseTo` must name an
  AuthnRequest this install issued in the last 10 minutes, and each is answered
  once; the IDs are stored in the database because the IdP's cross-site POST
  carries no SameSite=Lax session cookie. Unsolicited (IdP-initiated) responses
  are refused unless `escalated.sso.allow_idp_initiated` is on. An assertion ID
  is accepted once.
- **OAuth 2 / OIDC** (`league/oauth2-client`). Authorization-code flow with a
  single-use `state` kept in the session and PKCE (`S256`). The code is
  exchanged server to server and the profile read from the userinfo URL. A
  missing or mismatched `state`, an IdP `error`, or `email_verified: false` is
  refused. The calls use the `escalated.sso.http_client` Guzzle service;
  redefine it to add a proxy or other options.
- **JWT** (`firebase/php-jwt`). The token's `alg` must be the configured one
  (HS256/384/512 with the shared secret, RS256/384/512 with the public key in
  the same field); `none` and algorithm switching are refused. `exp` is
  required, `nbf`/`iat` are honoured with `escalated.sso.clock_skew` seconds of
  drift, `aud` must equal `escalated.sso.jwt_audience`, and `jti` is required
  and accepted once.

Request IDs, assertion IDs and `jti`s live in `escalated_sso_message_ids` until
they expire (plus drift), and expired rows are pruned on write.

### Users and roles

The email comes from the `sso_attr_email` attribute or claim (SAML falls back to
an email-shaped `NameID`), the name from `sso_attr_name` and the role from
`sso_attr_role`. The user is matched to a host user (`escalated.user_class`,
which needs a mapped `email` field) by email, case-insensitively.

- No match: refused, unless `escalated.sso.create_users` is on. Then a user is
  created: by the service named in `escalated.sso.user_provisioner` (an
  `Escalated\Symfony\Contract\SsoUserProvisionerInterface`) if set, otherwise by
  building `user_class` with the email (a constructor `$email` argument or
  `setEmail()`), `setName()` if it has one, and an unusable random password
  through the host's password hasher if it has `setPassword()`.
- With roles & permissions on and `escalated.sso.sync_roles` on, a role naming
  an existing Escalated role slug replaces the user's roles with that one; an
  unknown slug changes nothing.
- SSO never grants `ROLE_ESCALATED_ADMIN` or an agent profile: it only signs the
  user in.

The user is signed in with `Security::login()` on the firewall the SSO routes
sit behind (or `escalated.sso.firewall`). That dispatches the firewall's
`LoginSuccessEvent`, so the session is migrated and, with two-factor on, an
enrolled user still meets the challenge. A firewall with more than one
authenticator needs `escalated.sso.authenticator` (for example `form_login`).
They land on the page the firewall saved as the target path, else
`escalated.sso.redirect_to`, else the agent dashboard (admin reports for an
admin who is not an agent, the customer ticket list for everyone else). Every
refusal answers a generic 403 and logs the reason at warning level.

Escalated does not turn off your own password login; do that in your app if
you want SSO only.

### Settings

```yaml
escalated:
    access:
        sso: true
    sso:
        sp_entity_id: ~          # ESCALATED_SSO_SP_ENTITY_ID; empty = the metadata URL
        allow_idp_initiated: ~   # ESCALATED_SSO_ALLOW_IDP_INITIATED, default false
        jwt_audience: ~          # ESCALATED_SSO_JWT_AUDIENCE; empty = the request's scheme and host
        clock_skew: 120          # seconds, 0 to 300 (JWT)
        create_users: ~          # ESCALATED_SSO_CREATE_USERS, default false
        sync_roles: ~            # ESCALATED_SSO_SYNC_ROLES, default false
        user_provisioner: ~      # service id, see above
        firewall: ~              # default: the firewall of the SSO routes
        authenticator: ~         # needed when that firewall has several
        redirect_to: ~
```

Set `jwt_audience` explicitly (and `framework.trusted_hosts`): the default
comes from the request.

### Turning it on

1. Run the migrations (`Version20261101000001` adds `escalated_sso_message_ids`).
2. Set `escalated.access.sso: true` or `ESCALATED_SSO=true`, and clear the cache.
3. In Admin > Settings > SSO choose the provider and fill its fields (a SAML
   install needs the IdP certificate), and give the IdP the endpoints listed
   there.
4. Decide on `create_users` and `sync_roles`.

## Shared props

Every page's shared data (`_escalated_shared`) has `features.roles_permissions`,
`features.two_factor` and `features.sso` (booleans), `permissions` (the
user's slugs while roles are on, `['*']` for staff while off, `[]` for everyone
else) and `two_factor_required` (whether the signed-in staff user must still
enrol).
