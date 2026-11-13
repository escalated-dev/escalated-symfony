# Getting started

## Requirements

- PHP 8.2+
- Symfony 6.4 or 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## Installation

```bash
composer require escalated-dev/escalated-symfony
```

### 1. Register the bundle

If Symfony Flex is installed, the bundle is registered automatically. Otherwise, add it to `config/bundles.php`:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. Configure the bundle

Create `config/packages/escalated.yaml`:

```yaml
escalated:
    user_class: App\Entity\User
    route_prefix: /support
    ui_enabled: true
    table_prefix: escalated_
    tickets:
        allow_customer_close: true
        default_priority: medium
    sla:
        enabled: true
        business_hours_only: false
        business_hours:
            start: '09:00'
            end: '17:00'
            timezone: UTC
            days: [1, 2, 3, 4, 5]
```

### Import the routes

Create `config/routes/escalated.yaml`:

```yaml
escalated:
    resource: '@EscalatedBundle/config/routes.yaml'
```

The bundle cannot register routes from its container extension, so this import
is required. The file hands off to the bundle's `escalated` route loader, which
applies your configuration when the router loads it: the JSON API is always
registered; the customer, agent, admin and widget UI only when `ui_enabled` is
true; the newsletter routes only when `enable_newsletters` is true as well.
Every route is mounted under `route_prefix`.

### Inbound email (optional)

Replies and new tickets can arrive by email through your mail provider's inbound webhook. Set a shared secret:

```yaml
# config/packages/escalated.yaml
escalated:
    inbound_secret: '%env(ESCALATED_INBOUND_SECRET)%'
```

Then point the provider at:

```
POST {route_prefix}/escalated/webhook/email/inbound?adapter=postmark|mailgun|ses
X-Escalated-Inbound-Secret: <the secret>
```

The adapter can also be sent as an `X-Escalated-Adapter` header.

- With no secret configured, the webhook refuses every request.
- The same secret signs the Reply-To address on outbound mail (`reply+{id}.{hmac8}@domain`). Only that signed address links an inbound email to a ticket; `In-Reply-To` / `References` and a subject reference such as `[ESC-00001]` are not trusted, because they can be guessed.
- A matched email becomes a reply only when its `From` address (case-insensitive) is the ticket's requester: the guest email, or the requester user's email. The reply is posted as that requester. Identity is never taken from `From`, so an email naming an agent's address is not posted as that agent; agents reply in the app.
- Anything else (no match, or a sender who is not the requester) becomes a new ticket, so mail is never dropped and the matched ticket is left untouched. `From` can still be forged for the requester's own address, so also have your provider enforce SPF/DKIM/DMARC.
- To support another provider, implement `Escalated\Symfony\Mail\Inbound\InboundEmailParser`. Autoconfigured services implementing it are registered automatically.

### Outbound webhooks

Webhook URLs must use `http` or `https` and point to a public address. This is checked when a webhook is saved and again on every delivery. Private, loopback, link-local and reserved addresses are refused, deliveries never follow redirects, and each request connects to the address that passed the check. To deliver to receivers on your own network on purpose:

```yaml
escalated:
    webhooks:
        allow_private_networks: true
```

### Plugins

Admins activate and deactivate plugins in **Admin > Plugins**. Native plugins are services implementing `Escalated\Symfony\Plugin\PluginInterface`; plugins built with `@escalated-dev/plugin-sdk` run in the Node plugin runtime once `escalated.plugins.sdk.enabled` is true. Active plugins get pages, endpoints, webhooks, the canonical events and the plugin `ctx`. See [docs/plugins.md](plugins.md).

### Roles & permissions

Off by default. Set `escalated.access.roles_permissions: true` (or `ESCALATED_ROLES_PERMISSIONS=true`) after running the migrations, `bin/console escalated:permissions:seed` and `bin/console escalated:roles:assign you@example.com admin`; every admin, agent and staff API route then needs its permission from the user's Escalated roles as well as the `ESCALATED_ADMIN` / `ESCALATED_AGENT` gate. While it is off, **Admin > Roles** answers 404 and the gates decide everything. See [docs/access-control.md](access-control.md).

### Two-factor authentication

Off by default. Set `escalated.access.two_factor: true` (or `ESCALATED_TWO_FACTOR=true`) after running the migrations. Staff then enrol in TOTP at `{route_prefix}/admin/settings/two-factor`, and a staff user with an enrolment must pass `{route_prefix}/two-factor/challenge` after each sign-in before any admin or agent page. `escalated.two_factor.required` (or `ESCALATED_TWO_FACTOR_REQUIRED`, or the `two_factor_required` admin setting) makes every staff user enrol. `bin/console escalated:two-factor:reset you@example.com` clears a lost device. See [docs/access-control.md](access-control.md#two-factor-authentication).

### Single sign-on

Off by default. Set `escalated.access.sso: true` (or `ESCALATED_SSO=true`) after running the migrations. Admins then choose SAML, OAuth 2 / OIDC or JWT at `{route_prefix}/admin/settings/sso`, which also lists the endpoints to give the identity provider, and users sign in at `{route_prefix}/sso/login`. Users are matched by email; `escalated.sso.create_users` and `escalated.sso.sync_roles` are off by default. See [docs/access-control.md](access-control.md#single-sign-on).

### Embedding the widget on other sites

Load the widget on another site with `<script src="https://help.example.com/support/widget/api/embed.js" async></script>` after listing that site's origin, either in the `WidgetSettings` service's `$allowedOrigins` argument (for example `'%env(csv:ESCALATED_WIDGET_ALLOWED_ORIGINS)%'`) or in the admin setting `widget_allowed_origins`. An empty list allows no other site. See [docs/embedding-the-widget.md](embedding-the-widget.md).

### Verified guest access

Widget tickets, live chats and status lookups need an eight-digit code emailed to the guest (`POST {route_prefix}/widget/api/verification`), and tickets are read with expiring grants. The same codes and grants serve the mobile guest API under `{route_prefix}/api/v1/mobile/guest`. Configure `symfony/mailer` with a From address, and run the migrations. See [docs/guest-access.md](guest-access.md).

### Guest widget rate limits

The unauthenticated widget endpoints are limited per client IP: 5 guest tickets a minute (new tickets and live chat starts share this counter) and 10 guest replies a minute (ticket replies, chat messages and chat ratings), each with its own counter. Over the limit a request gets `429` with `Retry-After`. A reply is counted before its guest token is checked, so wrong-token guesses count too.

```yaml
escalated:
    guest_rate_limit:
        enabled: true            # false only if you already throttle upstream
        tickets_per_minute: 5
        replies_per_minute: 10
        requests_per_minute: 60  # every mobile guest API route
        cache_pool: cache.app    # any PSR-6 pool service id; use a shared (Redis) pool on multiple servers
```

The client IP is `Request::getClientIp()`. Behind a reverse proxy or load balancer, set `framework.trusted_proxies` and `trusted_headers`, or every guest shares the proxy's address.

### Schedule the background commands

Several features run from console commands rather than on a request. Run them from cron, Symfony Scheduler or your platform's job runner:

```cron
* * * * *  php bin/console escalated:check-sla-breaches      # mark overdue tickets, fire sla.breached
* * * * *  php bin/console escalated:process-delayed-actions # run delayed workflow actions that are due
* * * * *  php bin/console escalated:wake-snoozed-tickets    # reopen tickets whose snooze expired
*/5 * * * * php bin/console escalated:automations:run        # time-based automations
*/5 * * * * php bin/console escalated:escalations:run        # escalation rules
*/5 * * * * php bin/console escalated:chat:close-idle        # close idle live chats
* * * * *  php bin/console escalated:newsletters:dispatch    # only with enable_newsletters
0 3 * * *  php bin/console escalated:purge-expired          # data retention (Admin > Settings > Data retention)
* * * * *  php bin/console escalated:plugins:cron            # SDK plugins' cron schedules (with the plugin runtime)
```

Without the first two, SLA breaches are never recorded and delayed workflow actions never run.

### Host user key type (UUID / string users)

Escalated stores references to your app's users (ticket requester, assignee,
reply author, etc.). By default those columns are integers. If your `User`
entity's primary key is a **UUID or other string**, set the
`ESCALATED_USER_KEY_TYPE` environment variable so the Doctrine columns are
declared as `VARCHAR(255)` instead of `INTEGER` (and `getUserIdentifier()`
values are no longer cast to `int`):

```dotenv
# .env — one of: int (default) | bigint | uuid | string
ESCALATED_USER_KEY_TYPE=uuid
```

It is read from the environment (not the bundle config) because the Doctrine
type's SQL declaration is resolved outside the container. Existing integer-keyed
installs need no change — the default (`int`) produces the same schema and reads
ids back as `int`. Generate a Doctrine migration after changing it.

### 3. Run migrations

```bash
php bin/console doctrine:migrations:migrate
```

The bundle registers its own migrations, in the `Escalated\Symfony\Migrations` namespace, whenever DoctrineMigrationsBundle is enabled. You do not need a `migrations_paths` entry for them. They are written against Doctrine's schema API and run on MySQL/MariaDB, PostgreSQL and SQLite. Afterwards `php bin/console doctrine:schema:validate` reports the Escalated tables in sync with the entities.

#### Upgrading from 0.2.x or earlier

Earlier releases shipped one migration as `Escalated\Symfony\Migrations\Version20260406000001` and the rest as `DoctrineMigrations\Version…`. Doctrine could discover only one of the two groups, and most of them were MySQL-only SQL. They all now share the bundle namespace. Find your situation below:

- **You ran them as `DoctrineMigrations\Version…`** (by mapping that namespace to the bundle's `migrations/` directory, or by copying the files into your own). Nothing to do. On the next `migrate`, each of those migrations sees its old name in `doctrine_migration_versions`, records the new name without running again, and leaves the old row alone. Doctrine will list the old rows as "previously executed migrations that are not registered". Once the new names show as executed, you can remove the old rows with `php bin/console doctrine:migrations:version 'DoctrineMigrations\Version20260407000001' --delete` (one call per version). If you copied the files into your own migrations directory, delete the copies too.
- **You renamed Doctrine's metadata table** (`doctrine_migrations.storage.table_storage.table_name`). The automatic step reads the default table name. Record the versions you had already run under their new names instead: `php bin/console doctrine:migrations:version 'Escalated\Symfony\Migrations\Version20260407000001' --add`, and so on.
- **You created the tables some other way** (`doctrine:schema:update`, as the Docker demo used to). Record each already-applied bundle version with `doctrine:migrations:version 'Escalated\Symfony\Migrations\Version…' --add` before running `migrate`.
- **You mapped `Escalated\Symfony\Migrations` to `vendor/escalated-dev/escalated-symfony/migrations` yourself.** You can remove that entry; it is now redundant.

### 4. Set up security

Add the `ROLE_ESCALATED_ADMIN` role to admin users in your user provider. Agent access is determined by the presence of an `AgentProfile` entity linked to the user.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (Optional) Install Inertia

For the built-in frontend UI, install an Inertia bundle:

```bash
composer require rompetomp/inertia-bundle
# or
composer require skipthedragon/inertia-bundle
```

Set `ui_enabled: false` if you want to use only the API and services with a custom frontend.

## Embedding the admin in your own admin panel (optional)

Escalated's admin is a standalone panel at `{route_prefix}/admin` by default, and nothing changes unless you configure this. If your app already has an admin panel, you can show Escalated's admin screens inside it instead: under your URL prefix and security, inside your Vue/Inertia layout or Twig template (or an allow-listed iframe), with its menu listed in your own sidebar.

```yaml
# config/packages/escalated.yaml
escalated:
    ui:
        embed:
            enabled: true             # or ESCALATED_EMBED=true
            prefix: admin/support     # the admin moves to /admin/support/...
            guards: [ROLE_ADMIN]      # checked before Escalated's own admin gate, which stays on
            layout: Layouts/AdminLayout
```

Route names do not change, and Escalated's admin gate, two-factor and route permissions stay in place. See [docs/embedding-admin.md](embedding-admin.md) for the Vue + Inertia, Twig and iframe setups.

## Testing

```bash
vendor/bin/phpunit
```
