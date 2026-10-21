# Verified guest access

Guests who open a widget ticket, start a live chat or look up a ticket's status
first prove they control the email address. This follows escalated-laravel's
`docs/guest-access.md` and escalated-developer-context
`domain-model/guest-policy.md`, for the widget API under
`{route_prefix}/widget/api` and the browser guest portal under
`{route_prefix}/guest`.

## Host configuration

```yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
        headers:
            From: 'Support <support@example.com>'   # or escalated.guest_access.from_address

escalated:
    guest_access:
        ttl_minutes: 1440               # grant lifetime, clamped to 5 minutes .. 7 days
        accept_legacy_tokens: true      # see "Legacy tokens"
        codes_per_client_per_hour: 3
        codes_per_mailbox_per_hour: 10
        from_address: ~                 # From of the code emails; null uses the mailer's default headers
```

Run the bundle migrations (`Version20261021000001` adds four nullable ticket
columns and the `guest_verifications` table).

- Codes go out through `symfony/mailer`. Configure a working transport.
- Code budgets are kept in the `guest_rate_limit.cache_pool`. Use a shared pool
  (Redis, Memcached) on more than one server.
- Codes and grants are keyed from `kernel.secret`. Keep it the same on every
  server. Changing it invalidates outstanding codes and grants.

## Email proof

`POST /verification` with `{"email": "...", "purpose": "ticket"}`. The purpose
is `ticket`, `chat` or `lookup`; `ticket` and `lookup` need guest tickets
enabled.

The answer is `202` with `verification_id`, `expires_in: 600` and a generic
message. The eight-digit code is only in the email. Codes are stored as keyed
digests. Each code:
- expires after ten minutes
- allows five guesses
- is used once
- is bound to the normalised email and the purpose

Send `verification_id` and `verification_code` with the operation. A missing,
wrong, used, expired or mismatched proof is `422` with
`errors.verification_code`. Wrong guesses stay counted. The code is consumed in
the transaction that does the work, so a failed operation hands it back.

Code delivery is limited per mailbox and client IP
(`codes_per_client_per_hour`) and per mailbox across all clients
(`codes_per_mailbox_per_hour`). Over either limit, the answer is `429` with
`Retry-After`. The per-IP guest reply limit also applies to `/verification` and
`/lookup`.

## Tickets and grants

`POST /tickets` takes the proof next to the usual fields. It answers with
`guest_access_token` and `expires_at` (also `data.guest_token` and
`data.guest_access_expires_at`).

The token is an opaque grant: encrypted and authenticated, and expiring. Do not
parse it. `GET /tickets/{reference}` and `POST /tickets/{reference}/replies`
take it as `Authorization: Bearer <grant>` (or `X-Guest-Token`) and the
matching reference.

The ticket stores only a SHA-256 of the grant's nonce. Each of these invalidates
earlier grants:
- issuing a new grant (lookup rotates it)
- changing the ticket's guest email
- `GuestAccess::revoke($ticket)`

Guest responses send `Cache-Control: no-store` and `Referrer-Policy:
no-referrer`. Hosts should keep `Authorization` headers out of access logs.

## Status lookup

`POST /lookup` takes `email`, `reference`, `verification_id` and
`verification_code` (purpose `lookup`). It answers with `{"data": [{reference,
subject, guest_access_token, expires_at}]}`, listing the tickets with that
reference whose guest email is the verified address. The list may be empty; the
proof is consumed either way. A reference alone never opens a ticket.

## Live chat

`POST /chat/start` takes a `chat` proof. The chat is then addressed by the `id`
it returns, as before. A ticket proof cannot start a chat, and a chat proof
cannot open a ticket.

## Browser guest portal

With `ui_enabled`, the shared `Escalated/Guest/Create` and `Escalated/Guest/Show`
pages are served under `{route_prefix}/guest` while guest tickets are allowed
(`WidgetSettings::isAllowGuestTickets()`, default on; off answers 404):

| Route | Name | Does |
|---|---|---|
| `GET /guest/create` | `escalated.guest.tickets.create` | the form, with `verification_url` and `lookup_url` |
| `POST /guest/verification` | `escalated.guest.verification` | emails a code (`ticket` or `lookup`) |
| `POST /guest/lookup` | `escalated.guest.lookup` | as the widget's lookup |
| `POST /guest` | `escalated.guest.tickets.store` | opens a ticket with a `ticket` proof, then redirects to its page |
| `GET /guest/{grant}` | `escalated.guest.tickets.show` | the ticket, requester-facing fields only |
| `POST /guest/{grant}/reply` | `escalated.guest.tickets.reply` | the guest's reply (not on closed tickets) |
| `POST /guest/{grant}/rate` | `escalated.guest.tickets.rate` | the satisfaction rating |

The page address carries an expiring grant, never the permanent guest token,
and a legacy token does not open it. Pages are sent `Cache-Control: no-store`
and `Referrer-Policy: no-referrer`. Codes, budgets and per-IP limits are the
widget's. Bad input goes back to the form with the errors flashed under
`errors`.

## Legacy tokens

Tickets created before grants carry a permanent guest token. While
`accept_legacy_tokens` is `true` (the default for now), that token still opens
the ticket on the widget's ticket routes. Once a grant is issued for the ticket
(through lookup), the token stops working there. Set it to `false` to require a
verified grant everywhere; guests then get a new link through lookup.

## Not covered

- Attachments on guest tickets and replies: the bundle has no upload store.
- There is no mobile guest API.
- Live chat keeps its server-issued session token rather than an expiring chat
  grant.
