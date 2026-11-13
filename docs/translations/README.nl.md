<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.it.md">Italiano</a> ·
  <a href="README.ja.md">日本語</a> ·
  <a href="README.ko.md">한국어</a> ·
  <b>Nederlands</b> ·
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
  Voeg een complete helpdesk toe aan je Symfony-app.<br>
  Tickets, SLA's, escalatieregels, een inbox voor agents en een klantenportaal, draaiend op je eigen database.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Website</b></a> ·
  <a href="https://escalated.dev/docs"><b>Documentatie</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Wijzigingslog</b></a>
</p>

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-dark.png">
  <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-light.png" alt="De agent-inbox van Escalated: opgeslagen weergaven met aantallen, SLA-afteltimers en prioriteiten op elk ticket" width="100%">
</picture>

## Wat het doet

<table>
  <tr>
    <td width="40%">
      <h3>Een inbox waarin je agents de hele dag kunnen werken</h3>
      Gesprek, interne notities, standaardantwoorden en SLA-timers op één scherm. Tickets komen ook binnen per e-mail via Postmark, Mailgun of SES.
    </td>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-light.png" alt="Een geopend ticket in de agentwerkruimte met de antwoordeditor">
      </picture>
    </td>
  </tr>
  <tr>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-light.png" alt="De rapportpagina voor beheerders met reactietijden, CSAT en SLA-overschrijdingen">
      </picture>
    </td>
    <td width="40%">
      <h3>SLA's en escalatieregels die je kunt zien</h3>
      Reactie- en oplosdoelen per prioriteit met kantooruren, regels die escaleren of opnieuw toewijzen als een doel uitloopt, en rapporten over overschrijdingen.
    </td>
  </tr>
</table>

## Functies

<table>
<tr>
<td width="33%" valign="top">

**Tickets**
- Aanmaken, toewijzen, beantwoorden, oplossen, heropenen
- Configureerbare statusovergangen
- Een antwoord afsplitsen naar een nieuw ticket
- Uitstellen met automatisch terugzetten
- Tags, bijlagen, activiteitentijdlijn

</td>
<td width="33%" valign="top">

**Agentwerkruimte**
- Wachtrij met filters en opgeslagen weergaven
- Interne notities en standaardantwoorden
- Afdelingen met automatische toewijzing
- Realtime updates via Mercure

</td>
<td width="33%" valign="top">

**SLA's en escalatie**
- Reactie- en oplosdoelen per prioriteit
- Kantooruren
- Automatische detectie van overschrijdingen
- Escalatieregels en automatiseringen

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Klanten**
- Selfserviceportaal: openen, beantwoorden, volgen
- Geverifieerde gasttoegang met een code per e-mail
- Insluitbare widget met zoeken in de kennisbank
- Rate limits op gast-endpoints
- Kennisbank, aan of uit

</td>
<td width="33%" valign="top">

**E-mail**
- Inkomend via Postmark, Mailgun, SES
- Meldingen per gebeurtenis
- Correcte threading in mailclients
- Templates in je eigen huisstijl

</td>
<td width="33%" valign="top">

**Beheer en toegang**
- Afdelingen, SLA-beleid, tags, rapporten
- Rollen, rechten, tweestapsverificatie, SSO
- Beheer binnen je eigen adminpaneel
- Uitgaande webhooks en plugins

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Alle functies in detail</a></sub></p>

<p align="center">
  <a href="https://symfony.com"><img src="https://img.shields.io/badge/Symfony_6.4_or_7-000000?style=for-the-badge&logo=symfony&logoColor=white" alt="Symfony 6.4 or 7"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP_8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://www.doctrine-project.org"><img src="https://img.shields.io/badge/Doctrine-FC6A31?style=for-the-badge&logo=doctrine&logoColor=white" alt="Doctrine"></a>
  <a href="https://inertiajs.com"><img src="https://img.shields.io/badge/Inertia-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia"></a>
  <a href="https://vuejs.org"><img src="https://img.shields.io/badge/Vue_3-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue 3"></a>
</p>

## Snel aan de slag

```bash
composer require escalated-dev/escalated-symfony
composer require rompetomp/inertia-bundle
php bin/console doctrine:migrations:migrate
```

Voeg vóór het migreren `config/packages/escalated.yaml` toe en importeer de routes van de bundle in `config/routes/escalated.yaml`. Vereist PHP 8.2+ en Symfony 6.4 of 7.x. De [handleiding om te beginnen](../getting-started.md) bevat beide bestanden, de beveiligingsrollen en de geplande commando's.

## Meer informatie

- [Alle functies](../features.md) · [Aan de slag](../getting-started.md) · [Databaseverbinding](../configuration.md)
- [Architectuur en statusovergangen](../architecture.md) · [Ticketonderwerpen, acties en vertalingen](../customization.md)
- [Toegangsbeheer](../access-control.md) · [Tickettoegang en routering](../ticket-access-and-routing.md)
- [De widget insluiten](../embedding-the-widget.md) · [Gasttoegang](../guest-access.md)
- [Agentpaneel](../agent-panel.md) · [Het beheer insluiten](../embedding-admin.md)
- [Plugins](../plugins.md) · [Nieuwsbrieven](../newsletters.md)

Escalated is ook beschikbaar voor [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring), [WordPress](https://github.com/escalated-dev/escalated-wordpress) en [Filament](https://github.com/escalated-dev/escalated-filament). De UI staat in [escalated](https://github.com/escalated-dev/escalated).

## Licentie

MIT. Copyright (c) Escalated.dev. Zie [LICENSE](../../LICENSE).
