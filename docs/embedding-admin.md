# Embedding the admin in your own admin panel (optional)

Escalated ships a complete standalone admin at `/support/admin` (your
`route_prefix` + `/admin`), with its own sidebar and top bar. **That is the
default, and it needs no setup.** If your Symfony app already has an admin
panel, you can instead show Escalated's admin screens inside it: under your
sidebar and header, your URL prefix and your security rules.

This is an optional alternative to the standalone admin. Nothing on this page
has any effect until you turn it on: with no `ui.embed` configuration every
route, page, shared prop, header and gate is exactly what it was before (a test
compares the whole route table with a snapshot taken before the feature). The
design is in the ADR
[`2026-11-05-embeddable-admin`](https://github.com/escalated-dev/escalated-developer-context/blob/main/decisions/2026-11-05-embeddable-admin.md);
the frontend side is documented in
[`@escalated-dev/escalated` docs/embedding-admin.md](https://github.com/escalated-dev/escalated/blob/main/docs/embedding-admin.md).

## Contents

- [Pick your setup](#pick-your-setup)
- [Turning it on](#turning-it-on)
- [Configuration reference](#configuration-reference)
- [What changes, and what does not](#what-changes-and-what-does-not)
- [1. Vue + Inertia host](#1-vue--inertia-host)
- [2. Twig host](#2-twig-host)
- [3. iframe](#3-iframe)
- [The admin menu: `escalated.navigation`](#the-admin-menu-escalatednavigation)
- [Security](#security)
- [Turning it off](#turning-it-off)

## Pick your setup

| Your admin panel | Use | `mode` |
|---|---|---|
| Inertia + Vue in this app | [Vue + Inertia host](#1-vue--inertia-host) | `layout` |
| Twig templates (your own, or an admin bundle's) | [Twig host](#2-twig-host) (your admin template as the Inertia root view) | `layout` |
| Another app or origin, a non-Vue SPA, a CMS admin | [iframe](#3-iframe) | `iframe` |

## Turning it on

Add a `ui.embed` block to `config/packages/escalated.yaml`:

```yaml
escalated:
    ui:
        embed:
            enabled: true
            prefix: admin/support
            guards: [ROLE_ADMIN]
            layout: Layouts/AdminLayout
```

Or, in a PHP config file, build the same block with
`EmbedConfig::embedAdmin()` (the counterpart of escalated-laravel's
`Escalated::embedAdmin()`):

```php
// config/packages/escalated_embed.php
use Escalated\Symfony\Embed\EmbedConfig;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('escalated', EmbedConfig::embedAdmin(
        prefix: 'admin/support',
        guards: ['ROLE_ADMIN'],
        layout: 'Layouts/AdminLayout',
    ));
};
```

Or leave the keys out and set the env vars (`ESCALATED_EMBED=true`, and
`ESCALATED_EMBED_MODE`, `ESCALATED_EMBED_PREFIX`,
`ESCALATED_EMBED_FRAME_ANCESTORS` as needed).

Clear the cache after changing it (`bin/console cache:clear`): the routes are
cached, and in `prod` a changed env var only takes effect on the next cache
warm-up.

## Configuration reference

| Key (`escalated.ui.embed.*`) | Env var (read when the key is left out) | Default | Meaning |
|---|---|---|---|
| `enabled` | `ESCALATED_EMBED` | `false` | Master switch. While it is off, nothing below has any effect. Needs `ui_enabled`. |
| `mode` | `ESCALATED_EMBED_MODE` | `layout` | `layout` (Vue + Inertia or Twig host) or `iframe`. |
| `prefix` | `ESCALATED_EMBED_PREFIX` | `admin/support` | URL prefix the admin moves under. |
| `guards` | | `null` | Security attributes every embedded admin route requires, checked before Escalated's own gates: roles (`ROLE_ADMIN`) or attributes your voters decide. `null` adds none, so your firewall and `access_control` decide, as on the standalone admin. |
| `replace_admin_gate` | | `false` | `true` removes Escalated's admin gate (`ESCALATED_ADMIN`) from the embedded admin so your `guards` alone decide. Refused without `guards`. See [Security](#security). |
| `layout` | | `null` | Name of your Vue admin layout, matched against the frontend plugin's `layouts` option. |
| `root_view` | | `null` | Twig template your Inertia bundle renders embedded admin pages in ([Twig host](#2-twig-host)). `null`: your normal root view. |
| `frame_ancestors` | `ESCALATED_EMBED_FRAME_ANCESTORS` (comma-separated) | `[]` | iframe mode: exact origins allowed to frame the admin. Wildcards, `*`, paths and non-http(s) values are ignored. |

`EmbedConfig::embedAdmin()` takes the same settings as named arguments:
`prefix`, `guards`, `layout`, `rootView`, `mode`, `replaceAdminGate`,
`frameAncestors`.

Symfony has no per-route middleware, so the host's "admin middleware" is
`guards`: each one is passed to `isGranted()` on every embedded admin route,
after your firewall and before Escalated's checks. A refused visitor who is not
signed in goes to your firewall's entry point (your login page); anyone else
gets 403. You can also protect the prefix in `security.yaml`, as for any of your
own admin pages:

```yaml
security:
    access_control:
        - { path: ^/admin, roles: ROLE_ADMIN }
```

## What changes, and what does not

While embedding is on:

- Every admin route under `/support/admin` (the admin screens and settings,
  plugin admin and plugin pages, API tokens, import) is registered under your
  `prefix` instead. **Route names, controllers, methods and requirements do not
  change**, so everything that builds admin URLs by route name follows: the
  bundle's redirects and `path()` / `url()`, and a JavaScript router fed from
  Symfony's routes. The old `/support/admin/...` URLs answer 404.
- On those routes your `guards` run first, then Escalated's own checks as
  before: the feature switches, two-factor, route permissions (while roles &
  permissions is on) and the `ESCALATED_ADMIN` gate (unless you replace it).
- The shared `escalated` data gains `embed` and `navigation` (below), and the
  renderer shares it with your Inertia service as the `escalated` prop.

Unchanged: the agent panel (`/support/agent/...`), customer portal, guest
pages, knowledge base, widget, JSON API, plugin endpoints and webhooks, SSO,
the two-factor enrolment page (`/support/admin/settings/two-factor`, every
staff member's own) and challenge, and the newsletter admin
(`/support/admin/newsletters`).

`escalated.embed` on an embedded page:

```json
{
  "enabled": true,
  "mode": "layout",
  "layout": "Layouts/AdminLayout",
  "route": "escalated.admin.tickets.index",
  "base_url": "/admin/support",
  "allowed_origins": []
}
```

On every other Escalated page `enabled` is `false` and `route` is `null`.

The shared data lives in the `_escalated_shared` request attribute, as all of
Escalated's shared data does in this bundle. While embedding is on, the
renderer passes it to your Inertia service (`share('escalated', ...)`). Wire
the service into Escalated's renderer if you have not already:

```yaml
# config/services.yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: Escalated\Symfony\Rendering\InertiaUiRenderer
        autowire: true
        arguments:
            # the Inertia service of rompetomp/inertia-bundle or skipthedragon/inertia-bundle
            $inertia: '@rompetomp_inertia.inertia'
```

For your own pages outside Escalated, the same data comes from the
`Escalated\Symfony\Embed\EmbedConfig` service (`shared($request)`, the
`embed` object) and the `Escalated\Symfony\Embed\AdminNavigation` service
(`adminNavigation()`, the menu); share them with your Inertia service as
`escalated` if your sidebar lists Escalated's menu on every page.

## 1. Vue + Inertia host

Backend: turn embedding on (above) with your prefix and guards.

Frontend: register your admin layout under the name you gave `layout`, or add
`admin` to the plugin's `layoutScope`:

```js
import { EscalatedPlugin, resolveEscalatedPage } from '@escalated-dev/escalated';
import AdminLayout from './Layouts/AdminLayout.vue';
import AppLayout from './Layouts/AppLayout.vue';

createInertiaApp({
    resolve: (name) => resolveEscalatedPage(name, () => resolvePage(name)),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(EscalatedPlugin, {
                layout: AppLayout, // customer pages, as before
                layouts: { 'Layouts/AdminLayout': AdminLayout }, // embedded admin pages
                theme: { panel: { mode: 'light' } }, // match your admin
            })
            .mount(el);
    },
});
```

Your admin layout needs the slots the plugin's `layout` always required: a
`#header` slot (Escalated puts the page title there) and a default slot. List
Escalated's menu with `<EscalatedNavigation />`:

```vue
<!-- assets/Layouts/AdminLayout.vue -->
<script setup>
import { EscalatedNavigation } from '@escalated-dev/escalated';
</script>

<template>
    <div class="flex min-h-screen">
        <aside class="w-64 border-r bg-white p-3">
            <a href="/admin">Dashboard</a>
            <!-- ...your own items... -->
            <p class="mt-4 px-3 text-xs font-semibold uppercase text-gray-500">Support</p>
            <EscalatedNavigation />
        </aside>
        <div class="flex-1">
            <header class="border-b bg-white px-6 py-4"><slot name="header" /></header>
            <main class="p-6"><slot /></main>
        </div>
    </div>
</template>
```

Escalated renders each admin page chrome-free (no Escalated sidebar, top bar,
logo or footer) inside your layout. To use the same layout for customer pages
too, use `layout: AdminLayout, layoutScope: ['customer', 'admin']` instead of
`layouts`.

## 2. Twig host

If your admin layout is a Twig template, make a root view that contains your
chrome and the Inertia mount point, and point `root_view` at it:

```yaml
escalated:
    ui:
        embed:
            enabled: true
            prefix: admin/support
            guards: [ROLE_ADMIN]
            root_view: admin/escalated.html.twig
```

```twig
{# templates/admin/escalated.html.twig #}
<!DOCTYPE html>
<html lang="{{ app.request.locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Support - Acme Admin</title>
    {# your stylesheets and the Inertia entry, e.g. {{ vite_entry_link_tags('app') }} {{ vite_entry_script_tags('app') }} #}
    {{ inertiaHead(page) }}
</head>
<body>
    <header class="host-header">Acme Admin</header>
    <div class="host-shell">
        <nav class="host-sidebar" aria-label="Administration">
            <a href="/admin">Dashboard</a>
            <p class="menu-heading">Support</p>
            <ul>
                {% for item in escalated_admin_navigation() %}
                    <li><a href="{{ item.url }}"{% if item.active %} class="active" aria-current="page"{% endif %}>{{ item.label }}</a></li>
                {% endfor %}
            </ul>
        </nav>
        <main class="host-main">{{ inertia(page) }}</main>
    </div>
</body>
</html>
```

`inertia(page)` and `inertiaHead(page)` are your Inertia bundle's Twig
functions; `escalated_admin_navigation()` is Escalated's (see
[the admin menu](#the-admin-menu-escalatednavigation)), and
`escalated_embed()` returns the `escalated.embed` data for the current page.
Your Inertia service needs `setRootView()` / `getRootView()`, which the Inertia
bundles have.

Only embedded admin pages use this root view; every other page keeps your
normal one. Your Inertia entry needs no extra option: Escalated sees
`escalated.embed.enabled` and renders the page chrome-free (title + content).

## 3. iframe

For an admin that is not part of this app (another origin, another stack),
frame the embedded admin:

```yaml
escalated:
    ui:
        embed:
            enabled: true
            mode: iframe
            prefix: admin/support
            guards: [ROLE_ADMIN]
            frame_ancestors: ['https://admin.example.com']
```

or `ESCALATED_EMBED=true`, `ESCALATED_EMBED_MODE=iframe`,
`ESCALATED_EMBED_FRAME_ANCESTORS=https://admin.example.com`.

Embedded responses then carry
`Content-Security-Policy: frame-ancestors 'self' https://admin.example.com`
and no `X-Frame-Options` (the allow-listed `frame-ancestors` replaces it on
those responses only). The headers are set by a `kernel.response` listener that
runs after every other one (priority -2048), so a security-headers bundle or
listener that adds `X-Frame-Options` cannot undo them. If your app already
sends a CSP, it is kept and this directive is added as a second policy; if
your own policy has a `frame-ancestors` directive, add the same origins there
too, since browsers enforce both. No other response is touched.

On the framing page:

```html
<iframe id="support-admin" src="https://support.example.com/admin/support/tickets"
        style="width:100%;border:0" title="Support administration"></iframe>
<script type="module">
    import { connectEscalatedFrame } from '@escalated-dev/escalated/embed';

    const frame = connectEscalatedFrame(document.getElementById('support-admin'), {
        origin: 'https://support.example.com',
        onNavigate: ({ route, title }) => highlightMenu(route),
    });
    frame.setTheme('light');
</script>
```

The frame sizes itself to its content and reports each page change; the
protocol is in the frontend guide.

### Session cookies in a frame

- **Same site** (`admin.example.com` framing `support.example.com`): the
  default `SameSite=Lax` session cookie works.
- **Cross-site** (different registrable domains): the session cookie must be
  sent in a third-party context. Set it to `SameSite=None; Secure` and serve
  both sites over HTTPS:

  ```yaml
  # config/packages/framework.yaml
  framework:
      session:
          cookie_samesite: none
          cookie_secure: true
  ```

  Browsers that block third-party cookies will still not keep a session in a
  cross-site frame; use the Vue + Inertia or Twig setup in that case.
- CSRF protection is unchanged.

## The admin menu: `escalated.navigation`

While embedding is on, every Escalated page shares the admin menu, and
`AdminNavigation::adminNavigation(?UserInterface $user = null, ?Request $request = null)`
(the `Escalated\Symfony\Embed\AdminNavigation` service; in Twig
`escalated_admin_navigation()`) returns the same list for your own templates
and controllers, whether or not embedding is on:

```json
[
  {
    "key": "tickets",
    "label": "Tickets",
    "route": "escalated.admin.tickets.index",
    "url": "/admin/support/tickets",
    "icon": "tickets",
    "position": 20,
    "active": true,
    "badge": null,
    "plugin": null,
    "children": []
  }
]
```

It is filtered for the user: empty for anyone without `ROLE_ESCALATED_ADMIN`
(unless you replaced the gate, then any signed-in user), screens whose feature
is off are left out (Roles while roles & permissions is off, newsletters while
they are off), and while roles & permissions is on each item needs the
permission its route requires. Active plugins' admin menu items are included
(key `plugin:<slug>:<n>`, `route: null`), sorted by `position` with the core
items. Core `icon` keys match the frontend's `escalatedNavIcons`. Route names
are this bundle's: API Tokens is `escalated.admin.api_tokens.index` and Settings
`escalated.admin.settings.index`.

## Security

- **The admin gate stays on by default.** Your `guards` run *in addition to*
  Escalated's `ESCALATED_ADMIN` gate. Set `replace_admin_gate` only if your
  guards themselves restrict the routes to admins (e.g. `ROLE_ADMIN`): with it
  on, your guards are the only check on who reaches the embedded admin, and
  Escalated grants `ESCALATED_ADMIN` to any signed-in user on those routes
  (and nowhere else). Two-factor and route permissions still apply either way;
  the agent panel, plugin endpoints and the shared `is_admin` keep Escalated's
  gates, and ticket visibility still follows Escalated's own roles.
- **Framing is allow-listed.** In iframe mode only the origins you list (plus
  your own) may frame the admin; nothing else, and never `*`.
- **No open `postMessage`.** The frame talks only to an allow-listed parent
  origin and ignores messages from anywhere else.

## Turning it off

Remove the `ui.embed` block (or set `enabled: false` / `ESCALATED_EMBED=false`)
and clear the cache. The admin returns to `/support/admin` with Escalated's own
chrome.
