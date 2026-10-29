# Plugins

The behaviour every Escalated backend shares is in the
[plugin contract](https://github.com/escalated-dev/escalated-developer-context/blob/main/domain-model/plugin-contract.md).
This page covers what is specific to Symfony.

The bundle runs two kinds of plugin, side by side, in one list:

| Kind | Written in | Runs | Admin > Plugins shows it as |
|---|---|---|---|
| **Native** | PHP: a service implementing `Escalated\Symfony\Plugin\PluginInterface` | In your PHP process | `registered` |
| **SDK** | TypeScript, [`@escalated-dev/plugin-sdk`](https://github.com/escalated-dev/escalated-plugin-sdk) `definePlugin()` | In the Node plugin runtime, over the bridge | `composer` (installed by a package manager, npm here) |

Nothing is uploaded: the screen has no upload button (`can_upload: false`) and
there is no upload route. Installing a plugin is a deployment. Every plugin
starts inactive; nothing in it runs until an admin activates it in
**Admin > Plugins** (`{route_prefix}/admin/plugins`).

Run the migrations first. `Version20261029000001` creates `escalated_plugins`
(which plugins are active) and `escalated_plugin_store` (what `ctx.store` and
`ctx.config` keep).

## Native plugins

Extend `AbstractPlugin` and register the class as a service. Autoconfiguration
tags it `escalated.plugin`; without autoconfiguration, add the tag yourself.

```php
use Escalated\Symfony\Plugin\AbstractPlugin;
use Escalated\Symfony\Plugin\PluginContext;

final class CrmPlugin extends AbstractPlugin
{
    public function manifest(): array
    {
        return [
            'name' => '@acme/escalated-plugin-crm',
            'version' => '1.0.0',
            'description' => 'Links tickets to CRM contacts.',
            'pages' => [['route' => 'settings', 'component' => 'CrmSettings', 'menu' => ['label' => 'CRM', 'section' => 'admin']]],
            'endpoints' => [['method' => 'GET', 'path' => '/CrmSettings'], ['method' => 'POST', 'path' => '/contacts/:id']],
            'webhooks' => [['method' => 'POST', 'path' => '/inbound']],
            'filterHooks' => [['hook' => 'my.value', 'priority' => 10]],
        ];
    }

    public function handleAction(string $hook, array $event, PluginContext $ctx): void
    {
        if ('ticket.created' === $hook) {
            $ctx->storeInsert('seen', ['ticket' => $event['id']]);
        }
    }

    public function handleEndpoint(string $method, string $path, array $request, PluginContext $ctx): mixed
    {
        return match ($path) {
            '/CrmSettings' => ['api_key' => null !== $ctx->configGet('api_key') ? 'set' : 'unset'],
            '/contacts/:id' => ['updated' => $request['params']['id']],
        };
    }
}
```

The manifest has the SDK's shape: `name` (required), `version`,
`description`, `author`, `homepage`, `pages`, `components`, `widgets`,
`endpoints`, `webhooks` and `filterHooks`. A native plugin is offered every
action; return early from `handleAction()` for the ones it does not want.

The slug is the name without the leading `@` and with `/` as `--`
(`@acme/escalated-plugin-crm` is `acme--escalated-plugin-crm`). Two plugins
with one slug stop the registry with an error naming both.

A native plugin is your code with your process's privileges, and nothing can
time it out. Only register plugins you would merge into your own code.

## SDK plugins

SDK plugins run in [`@escalated-dev/plugin-runtime`](https://github.com/escalated-dev/escalated-plugin-runtime),
a Node process the bundle starts and talks to over stdio (JSON-RPC 2.0, the
contract's "SDK bridge"). It is off by default:

```yaml
# config/packages/escalated.yaml
escalated:
    plugins:
        sdk:
            enabled: true
            # Defaults shown. runtime_command may also be a list of arguments,
            # run without a shell.
            runtime_command: 'node node_modules/@escalated-dev/plugin-runtime/build/bin/escalated-plugins.js'
            runtime_cwd: null   # where node_modules is; null is %kernel.project_dir%
```

```bash
npm install @escalated-dev/plugin-runtime @escalated-dev/plugin-slack
```

The runtime is started on first use, in the PHP process that needs it: when
the plugins screen lists plugins, or when an active SDK plugin has an event,
page, endpoint or webhook to serve. A request that touches no active SDK
plugin never starts it. Under PHP-FPM that is once per request that needs it,
so keep SDK plugins to the ones you use.

If the runtime cannot start, or dies, the host carries on: SDK plugins are
missing from the list, their actions are dropped with a warning, filters
return their input, and their endpoints and webhooks answer 503. A dead
runtime is started again on the next use, backing off up to five minutes in a
long-running process. On Windows the bridge cannot time out a read from the
runtime and does not keep its stderr; run it on Linux in production.

SDK plugins are installed with npm, so Admin > Plugins cannot uninstall them
(the Delete button is hidden and the route answers with an error). Remove the
package to remove the plugin.

## What a plugin gets

| Plugin declares | Served at | Who |
|---|---|---|
| `pages` | `GET {route_prefix}/admin/plugins/{slug}/{route}`, rendered as `Escalated/Plugin/Page` with the props of the plugin's own `GET /{component}` endpoint (or `{}`) | Admins, plus the page's `capability` |
| `endpoints` | `{route_prefix}/api/plugins/{slug}{path}` | Admins, plus the endpoint's `capability` |
| `webhooks` | `{route_prefix}/webhooks/plugins/{slug}{path}` | Anyone. The plugin must authenticate the sender. |
| `pages[].menu`, `components`, `widgets` | Shared with every screen as `escalated.plugins` (`menuItems`, `pageComponents`, `dashboardWidgets`) in the `_escalated_shared` request attribute | Viewers who hold the item's `capability`; admin menu items for admins only |

Only active plugins answer; anything else is a 404. All plugin pages share one
route, `escalated.plugin.page` (`{slug}`, `{route}`), rather than one named
route per page, because the routes are compiled before the plugin runtime
runs. Link to a page by its URL, as `menuItems[].route` does.

- A `capability` is a Symfony security attribute: a role such as
  `ROLE_CRM_ADMIN`, or anything one of your voters decides. The bundle checks
  it with `isGranted()`; there is no admin bypass.
- Endpoint calls that change state (anything but GET, HEAD and OPTIONS) must be
  sent by a page script, with `X-Requested-With` or `X-Inertia`. A cross-site
  form cannot set those, so it cannot ride on an admin's session.
- Handlers receive the SDK's request (`httpContract: 1`): `params` from the
  `:name` segments of the declared path, `query`, the parsed `body`,
  `rawBodyBase64` (the exact bytes, for signature checks), `clientIp`, and
  lowercase `headers` without `Cookie`, `Authorization`, `X-XSRF-TOKEN`,
  `X-CSRF-TOKEN` or `Proxy-*`. They are dispatched on the declared path.
- Return a value for a JSON 200, or the SDK's `httpResponse()` envelope
  (`__escalated_http: 1`, `status`, `format`, `headers`, `body`) for another
  status, text, or headers. A malformed envelope is a 502. A handler that
  throws is a 500 whose body says nothing about the error; the error goes to
  the log.

## Events

Every canonical event of
[ADR 2026-10-21](https://github.com/escalated-dev/escalated-developer-context/blob/main/decisions/2026-10-21-event-vocabulary.md)
the bundle emits (the ones webhooks and workflows get) reaches active plugins'
actions under that name. Legacy names never do. Events are collected during
the request and delivered on `kernel.terminate` (`console.terminate` for
commands), after the change is written and the response is sent, so a slow or
failing plugin never fails or delays the request. A plugin that throws is
logged.

The payload carries the subject flat and under its own key:

- ticket events: the ticket (`id`, `reference`, `subject`, `title`,
  `description`, `status`, `priority`, `channel`, `ticket_type`,
  `department_id`, `assigned_to`, `requester_id`, `contact_id`, `metadata`,
  `tags`, `created_at`, `updated_at`) plus `ticket`, and the event's extras:
  `old_status`/`new_status`, `agent_id`, `previous_agent_id`,
  `old_priority`/`new_priority`, `old_department_id`/`new_department_id`,
  `tag` (`{id, name, slug}`, one event per tag), `changes`;
- `reply.created` and `note.created`: the reply (`id`, `ticket_id`, `body`,
  `type`, `is_internal`, `user_id`, `created_at`, `updated_at`) plus `reply`
  and `ticket`;
- `sla.breached`: the ticket plus `ticket` and `type` (`first_response` or
  `resolution`);
- `chat.*`: `{session: {session_id, ticket_id, status, agent_id,
  department_id, ...}}`, never the visitor's token or email.

Activation also fires `plugin.activated`, `plugin.deactivated` and
`plugin.uninstalling` (`{plugin, slug}`) to every active plugin.

## Filters

`PluginRegistry::applyFilter($hook, $value)` runs active plugins' filters,
lowest `priority` first, and returns the result; a filter that throws is
skipped. The bundle applies no filter points of its own yet; call it from your
code where you want one. SDK filters run in the runtime in a single call, at
the place of the lowest-priority SDK filter for that hook.

## The context

Everything in `ctx` is scoped to the plugin's name: a `plugin` parameter from
the runtime is never trusted, so one plugin cannot read another's store or
config through `ctx`.

- `ctx.store.query` filters in PHP over the plugin's rows in one collection:
  equality (an array field matches when it holds the value), `$gt`, `$gte`,
  `$lt`, `$lte`, `$ne`, `$in`, `$nin` on dotted field names, `orderBy`
  (numbers, then strings, missing last), `order`, `limit`. A field name that
  is not a dotted path is an error.
- `ctx.config.set` merges. Config is never put on a page.
- `ctx.tickets` and `ctx.replies` go through `TicketService`, so what a plugin
  writes fires the same events as anything else. `create` takes `subject` (or
  `title`), `description` (or `body`), `priority`, `status`, `department_id`,
  `ticket_type`, `channel` (default `api`), `metadata`, and `contact_id` or
  `requester_email`/`requester_name` (the contact is found or created);
  `update` takes the same minus the channel and requester. `query` filters on
  `id`, `reference`, `status`, `priority`, `channel`, `ticket_type`,
  `department_id`, `requester_id`, `contact_id`, `assigned_to` (replies:
  `ticket_id`, `is_internal`, `type`), at most 500 rows; any other key is an
  error. `ctx.replies.create` needs `ticket_id` and `body`; `is_internal: true`
  makes a note.
- Writes act as the signed-in user of the request the plugin is serving, or
  as no user outside a request. A plugin never picks who it acts as.
- `ctx.contacts` are Escalated's contacts, found or created by email.
  `ctx.agents` are the users with an agent profile, as the shared pages list
  them (`id`, `name`, `email`).
- `ctx.broadcast.*` goes through the bundle's `BroadcasterInterface`
  (`NullBroadcaster` unless you replace it).
- `ctx.emit` fires an action to the active plugins. The canonical event names
  and the `plugin.*` lifecycle names belong to the host and are dropped.
- `ctx.log` writes to the bundle's logger with the plugin's name.

A native plugin reaches all of these through `PluginContext`: typed helpers
for config, store, emit, log and the current user, and
`$ctx->call('tickets.create', ['data' => [...]])` for the rest.
