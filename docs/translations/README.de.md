<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <b>Deutsch</b> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.it.md">Italiano</a> ·
  <a href="README.ja.md">日本語</a> ·
  <a href="README.ko.md">한국어</a> ·
  <a href="README.nl.md">Nederlands</a> ·
  <a href="README.pl.md">Polski</a> ·
  <a href="README.pt-BR.md">Português (BR)</a> ·
  <a href="README.ru.md">Русский</a> ·
  <a href="README.tr.md">Türkçe</a> ·
  <a href="README.zh-CN.md">简体中文</a>
</sub></p>

<p align="center">
  <a href="https://escalated.dev"><img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/logo.png" width="72" height="72" alt="Escalated"></a>
</p>

<h1 align="center">Escalated for Symfony</h1>

<p align="center">
  Füge deiner Symfony-App einen vollständigen Helpdesk hinzu.<br>
  Tickets, SLAs, Eskalationsregeln, ein Agenten-Posteingang und ein Kundenportal, direkt auf deiner eigenen Datenbank.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Website</b></a> ·
  <a href="https://escalated.dev/docs"><b>Dokumentation</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Änderungsprotokoll</b></a>
</p>

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-dark.png">
  <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-light.png" alt="Der Agenten-Posteingang von Escalated: gespeicherte Ansichten mit Zählern, SLA-Countdowns und Prioritäten an jedem Ticket" width="100%">
</picture>

## Was es kann

<table>
  <tr>
    <td width="40%">
      <h3>Ein Posteingang, in dem deine Agenten den ganzen Tag arbeiten können</h3>
      Konversation, interne Notizen, Textbausteine und SLA-Timer auf einem Bildschirm. Tickets kommen auch per E-Mail über Postmark, Mailgun oder SES an.
    </td>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-light.png" alt="Ein geöffnetes Ticket im Agenten-Arbeitsbereich mit dem Antwort-Editor">
      </picture>
    </td>
  </tr>
  <tr>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-light.png" alt="Die Berichtsseite im Adminbereich mit Antwortzeiten, CSAT und SLA-Verstößen">
      </picture>
    </td>
    <td width="40%">
      <h3>SLAs und Eskalationsregeln, die du im Blick hast</h3>
      Reaktions- und Lösungsziele pro Priorität mit Geschäftszeiten, Regeln, die eskalieren oder neu zuweisen, wenn ein Ziel verfehlt wird, und Berichte über Verstöße.
    </td>
  </tr>
</table>

## Funktionen

<table>
<tr>
<td width="33%" valign="top">

**Tickets**
- Erstellen, zuweisen, antworten, lösen, wieder öffnen
- Konfigurierbare Statusübergänge
- Eine Antwort in ein neues Ticket aufteilen
- Zurückstellen mit automatischer Wiedervorlage
- Tags, Anhänge, Aktivitätsverlauf

</td>
<td width="33%" valign="top">

**Agenten-Arbeitsbereich**
- Warteschlange mit Filtern und gespeicherten Ansichten
- Interne Notizen und Textbausteine
- Abteilungen mit automatischer Zuweisung
- Echtzeit-Updates über Mercure

</td>
<td width="33%" valign="top">

**SLAs und Eskalation**
- Reaktions- und Lösungsziele pro Priorität
- Geschäftszeiten
- Automatische Erkennung von Verstößen
- Eskalationsregeln und Automatisierungen

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Kunden**
- Self-Service-Portal: eröffnen, antworten, verfolgen
- Verifizierter Gastzugang per E-Mail-Code
- Einbettbares Widget mit Suche in der Wissensdatenbank
- Ratenbegrenzung für Gast-Endpunkte
- Wissensdatenbank, ein- oder ausschaltbar

</td>
<td width="33%" valign="top">

**E-Mail**
- Eingehend über Postmark, Mailgun, SES
- Benachrichtigungen pro Ereignis
- Korrekte Thread-Darstellung in Mail-Clients
- Vorlagen mit deinem Branding

</td>
<td width="33%" valign="top">

**Administration und Zugriff**
- Abteilungen, SLA-Richtlinien, Tags, Berichte
- Rollen, Berechtigungen, Zwei-Faktor, SSO
- Adminbereich in deinem eigenen Admin-Panel
- Ausgehende Webhooks und Plugins

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Jede Funktion im Detail</a></sub></p>

<p align="center">
  <a href="https://symfony.com"><img src="https://img.shields.io/badge/Symfony_6.4_or_7-000000?style=for-the-badge&logo=symfony&logoColor=white" alt="Symfony 6.4 or 7"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP_8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://www.doctrine-project.org"><img src="https://img.shields.io/badge/Doctrine-FC6A31?style=for-the-badge&logo=doctrine&logoColor=white" alt="Doctrine"></a>
  <a href="https://inertiajs.com"><img src="https://img.shields.io/badge/Inertia-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia"></a>
  <a href="https://vuejs.org"><img src="https://img.shields.io/badge/Vue_3-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue 3"></a>
</p>

## Schnellstart

```bash
composer require escalated-dev/escalated-symfony
composer require rompetomp/inertia-bundle
php bin/console doctrine:migrations:migrate
```

Lege vor der Migration `config/packages/escalated.yaml` an und importiere die Routen des Bundles in `config/routes/escalated.yaml`. Voraussetzungen: PHP 8.2+ und Symfony 6.4 oder 7.x. Die [Einstiegsanleitung](../getting-started.md) enthält beide Dateien, die Security-Rollen und die geplanten Befehle.

## Mehr erfahren

- [Alle Funktionen](../features.md) · [Erste Schritte](../getting-started.md) · [Datenbankverbindung](../configuration.md)
- [Architektur und Statusübergänge](../architecture.md) · [Ticket-Betreffe, Aktionen und Übersetzungen](../customization.md)
- [Zugriffskontrolle](../access-control.md) · [Ticketzugriff und Routing](../ticket-access-and-routing.md)
- [Widget einbetten](../embedding-the-widget.md) · [Gastzugang](../guest-access.md)
- [Agenten-Panel](../agent-panel.md) · [Adminbereich einbetten](../embedding-admin.md)
- [Plugins](../plugins.md) · [Newsletter](../newsletters.md)

Escalated gibt es auch für [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring), [WordPress](https://github.com/escalated-dev/escalated-wordpress) und [Filament](https://github.com/escalated-dev/escalated-filament). Die Oberfläche liegt in [escalated](https://github.com/escalated-dev/escalated).

## Lizenz

MIT. Copyright (c) Escalated.dev. Siehe [LICENSE](../../LICENSE).
