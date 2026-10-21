# Embedding the widget on other sites

The support widget, ticket form and live chat can run on a site other than
the one Escalated is installed on: a store, a marketing site or a customer
portal loads one script from your Escalated install. No other site can do
this until you list it.

## 1. Allow the embedding sites

Either in the `WidgetSettings` service configuration, for example from the
environment:

```yaml
# config/services.yaml
services:
    Escalated\Symfony\Widget\WidgetSettings:
        arguments:
            $enabled: true
            $allowedOrigins: '%env(csv:ESCALATED_WIDGET_ALLOWED_ORIGINS)%'
```

```dotenv
ESCALATED_WIDGET_ALLOWED_ORIGINS=https://shop.example.com,https://*.example.org
```

or in the admin settings page (`{route_prefix}/admin/settings`, **Sites that
can embed the widget**, one per line), stored as the `widget_allowed_origins`
setting. Both lists are combined.

Each entry is an origin: `scheme://host[:port]` over `http` or `https`, no
path, query or user info. Entries are compared lowercased with default ports
dropped. `https://*.example.org` matches every subdomain of `example.org`, at
any depth, but not `example.org` itself and not `http://`. A bare `*` is
rejected, and the admin page refuses to save a list with an invalid entry.

An empty list allows no other site. (Before this release an empty list
allowed every site; see the changelog.)

## 2. Add the script to the other site

```html
<script src="https://help.example.com/support/widget/api/embed.js" async></script>
```

The script derives the API path (`/support/widget/api`) from its own URL. If
you serve the bundle from somewhere else, set `data-base-url` and
`data-widget-path`. All host parameters (tabs, department, prefill, metadata,
title, the JavaScript API) are described in the frontend package's
[embedding guide](https://github.com/escalated-dev/escalated/blob/main/docs/embedding-the-widget.md).

## What the server does

`Escalated\Symfony\EventSubscriber\WidgetCorsSubscriber` handles every request
under `{route_prefix}/widget/api` (chat included). For a request whose
`Origin` is on the list it:

- answers preflight requests (`OPTIONS` with `Access-Control-Request-Method`)
  with `204`, `Access-Control-Allow-Origin: <origin>`,
  `Access-Control-Allow-Methods: GET, POST, OPTIONS`,
  `Access-Control-Allow-Headers: Accept, Authorization, Content-Type,
  X-Requested-With, X-Guest-Token`, `Access-Control-Max-Age: 600` and
  `Vary: Origin`;
- adds `Access-Control-Allow-Origin: <origin>`,
  `Access-Control-Expose-Headers: Retry-After` and `Vary: Origin` to every
  answer, including validation errors (422), missing guest tokens (401), rate
  limits (429) and error pages. `Access-Control-Allow-Credentials` is never
  sent;
- drops the request's cookies before the session and firewall read them, so
  the visitor is a guest even if the same browser is signed in to Escalated.
  Guest tokens (`X-Guest-Token`) still work.

A preflight from any other origin gets `204` without a grant. Other requests
from unlisted origins, and same-origin requests, are answered as before and
without CORS headers, so the browser withholds the answer from the other page.
The widget routes have no CSRF check; that is unchanged. Rate limits and the
`WidgetSettings` switches apply to embedded requests unchanged.

### Host parameters

`/config` returns `accepts_metadata: true`. Widget tickets (`POST
/tickets`) store the `metadata` the page sends in the ticket's `metadata`,
next to `source: widget`; live chats (`POST /chat/start`) store it in the
ticket's `chat_metadata`. The values come from the visitor's browser, so only a
flat map is kept: up to 25 entries, keys of letters, digits, `_ . : -` (64
characters at most), and string, number or boolean values (strings trimmed and
cut to 500 characters). `page_url` is kept only when it is an http(s) URL of at
most 2048 characters. `embedded_on` is always set by the server from the
request's allowed `Origin` and cannot be supplied by the page. A `metadata`
that is not an object, or has more than 50 keys, is refused with 422. Treat all
of it as unverified visitor input.

### Widget API

The bundled widget calls, under `{route_prefix}/widget/api`: `GET /config`,
`GET /articles?q=`, `GET /articles/{slug}`, `POST /tickets`,
`GET /chat/availability`, `POST /chat/start`, and `GET`/`POST
/chat/{id}/messages`, `POST /chat/{id}/typing`, `/end` and `/rate`, where
`{id}` is the random guest token `/chat/start` returns as `id`, plus
`POST /verification` and `POST /lookup`. `/config` returns
`guest_verification_required: true` and `status_lookup_enabled: true`: tickets,
chats and status lookups need an emailed code. See
[guest-access.md](guest-access.md). The original routes and response envelopes
(`data`, `/chat/sessions/{reference}/...` with `X-Guest-Token`) still work.

### The script

`GET {route_prefix}/widget/api/embed.js` serves `public/widget/escalated-widget.js`
from this bundle, the standalone build of `@escalated-dev/escalated`
(`dist/widget/escalated-widget.js`), with `Cache-Control: public,
max-age=3600`, an `ETag` (304 on `If-None-Match`),
`X-Content-Type-Options: nosniff`, `Cross-Origin-Resource-Policy:
cross-origin` and `Access-Control-Allow-Origin: *`. `assets:install` also
copies it to `public/bundles/escalated/widget/` if you prefer to serve it as a
static file.

## Troubleshooting

- **The browser reports a CORS error.** The page's origin is not on the list,
  or differs in scheme or port (`http://` vs `https://`, `:8080`).
- **Another CORS bundle answers first.** If `nelmio/cors-bundle` or a proxy is
  configured for `{route_prefix}/widget`, it may answer preflights with its own
  settings. Leave those paths out of it.
