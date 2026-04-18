# Escalated Symfony — Docker demo

A one-command demo of the `escalated-dev/escalated-symfony` bundle running inside a throwaway Symfony 7 host app.

## Run it

```bash
cd docker
cp .env.example .env          # optional, only for port overrides
docker compose up --build
```

Then:

- **http://localhost:8000/demo** — click a seeded user to log in.
- **http://localhost:8025** — Mailpit UI for outbound email preview.

## What's inside

- **app** — PHP 8.3 + Symfony 7 host + the bundle.
  `docker/host-app/composer.json` pulls the bundle from the repo root via a Composer path repository — edits to `../src/` show up on rebuild.
- **db** — Postgres 16 (alpine).
- **mailpit** — SMTP sink.

Every `docker compose up` resets: drops and recreates the database, runs `doctrine:migrations:migrate`, syncs the host app's `User` entity schema with `doctrine:schema:update`, then loads `DemoFixtures` (5 users, 2 departments, 1 SLA policy, 3 tags, 8 tickets with replies).

## Seeded users

Staff:

| Role     | Email                            | Password   |
|----------|----------------------------------|------------|
| Admin    | alice@demo.test                  | `password` |
| Agents   | bob / carol @demo.test           | `password` |

Customers: `frank` / `grace` @acme.example, `henry` @globex.example — `password`.

## Scope + known limits

- **No Inertia UI in this demo.** `escalated.yaml` sets `ui_enabled: false`; the bundle's routes stay wired up but agent/admin responses are JSON, not HTML. The Vue UI requires `rompetomp/inertia-bundle` + a Vite pipeline — treated as a follow-up. The `/demo` picker page is plain Twig and is the landing you get to see the demo working at all.
- **`php -S` dev server** only. No FrankenPHP, no php-fpm + nginx.
- **Ephemeral data** — every restart reseeds. Don't rely on data persisting.
- **`APP_ENV=demo`** hard-required by `DemoController` to expose `/demo/*` routes. In any other env those routes 404.
