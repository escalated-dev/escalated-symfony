# Newsletters

## Newsletters (optional, disabled by default)

Admin-only broadcast feature for sending Markdown emails to contacts. Off by default — pass `enabled: true` to `NewsletterDispatcher` to turn it on.

Register the newsletter Twig namespace in your bundle config:

```yaml
twig:
  paths:
    '%kernel.project_dir%/vendor/escalated-dev/escalated-symfony/templates': EscalatedNewsletter
```

Wire the services in `services.yaml`:

```yaml
Escalated\Symfony\Service\Newsletter\NewsletterRenderer:
  arguments:
    $twig: '@twig'
    $baseUrl: '%env(APP_URL)%'
    $defaultTheme: 'default'
    $trackingEnabled: true
    $markdownToHtml: !service { class: 'Closure', factory: 'fn (string $md) => Symfony\Component\String\u($md)->toString() }
    # ^^^ swap for league/commonmark, parsedown, etc. in your app

Escalated\Symfony\Service\Newsletter\NewsletterDispatcher:
  arguments:
    $enabled: true
    $batchSize: 50
    # …
```

Then run the dispatcher on a cron (Symfony Messenger / cron job / Scheduler):

```php
$container->get(NewsletterDispatcher::class)->dispatchBatch();
```

Custom themes go in `templates/newsletter_themes/<slug>.html.twig`.
