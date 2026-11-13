<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <b>Français</b> ·
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
  Ajoutez un helpdesk complet à votre application Symfony.<br>
  Tickets, SLA, règles d'escalade, une boîte de réception pour les agents et un portail client, sur votre propre base de données.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Site web</b></a> ·
  <a href="https://escalated.dev/docs"><b>Documentation</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Journal des modifications</b></a>
</p>

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-dark.png">
  <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-light.png" alt="La boîte de réception des agents d'Escalated : vues enregistrées avec compteurs, comptes à rebours SLA et priorités sur chaque ticket" width="100%">
</picture>

## Ce qu'il fait

<table>
  <tr>
    <td width="40%">
      <h3>Une boîte de réception où vos agents peuvent travailler toute la journée</h3>
      Conversation, notes internes, réponses prédéfinies et minuteurs SLA sur un seul écran. Les tickets arrivent aussi par e-mail via Postmark, Mailgun ou SES.
    </td>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-light.png" alt="Un ticket ouvert dans l'espace de travail de l'agent avec l'éditeur de réponse">
      </picture>
    </td>
  </tr>
  <tr>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-light.png" alt="La page des rapports d'administration avec les temps de réponse, le CSAT et les dépassements de SLA">
      </picture>
    </td>
    <td width="40%">
      <h3>Des SLA et des règles d'escalade que vous voyez</h3>
      Objectifs de réponse et de résolution par priorité avec heures ouvrées, règles qui escaladent ou réassignent en cas de retard, et rapports sur les dépassements.
    </td>
  </tr>
</table>

## Fonctionnalités

<table>
<tr>
<td width="33%" valign="top">

**Tickets**
- Créer, assigner, répondre, résoudre, rouvrir
- Transitions de statut configurables
- Scinder une réponse en un nouveau ticket
- Mise en attente avec réveil automatique
- Tags, pièces jointes, historique d'activité

</td>
<td width="33%" valign="top">

**Espace de travail agent**
- File avec filtres et vues enregistrées
- Notes internes et réponses prédéfinies
- Départements avec attribution automatique
- Mises à jour en temps réel via Mercure

</td>
<td width="33%" valign="top">

**SLA et escalade**
- Objectifs de réponse et de résolution par priorité
- Heures ouvrées
- Détection automatique des dépassements
- Règles d'escalade et automatisations

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Clients**
- Portail en libre-service : ouvrir, répondre, suivre
- Accès invité vérifié par un code envoyé par e-mail
- Widget intégrable avec recherche dans la base de connaissances
- Limites de débit sur les endpoints invités
- Base de connaissances, activée ou non

</td>
<td width="33%" valign="top">

**E-mail**
- Réception via Postmark, Mailgun, SES
- Notifications par événement
- Fils de discussion corrects dans les clients mail
- Modèles à vos couleurs

</td>
<td width="33%" valign="top">

**Administration et accès**
- Départements, politiques SLA, tags, rapports
- Rôles, permissions, double authentification, SSO
- Administration dans votre propre panneau d'admin
- Webhooks sortants et plugins

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Toutes les fonctionnalités en détail</a></sub></p>

<p align="center">
  <a href="https://symfony.com"><img src="https://img.shields.io/badge/Symfony_6.4_or_7-000000?style=for-the-badge&logo=symfony&logoColor=white" alt="Symfony 6.4 or 7"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP_8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://www.doctrine-project.org"><img src="https://img.shields.io/badge/Doctrine-FC6A31?style=for-the-badge&logo=doctrine&logoColor=white" alt="Doctrine"></a>
  <a href="https://inertiajs.com"><img src="https://img.shields.io/badge/Inertia-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia"></a>
  <a href="https://vuejs.org"><img src="https://img.shields.io/badge/Vue_3-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue 3"></a>
</p>

## Démarrage rapide

```bash
composer require escalated-dev/escalated-symfony
composer require rompetomp/inertia-bundle
php bin/console doctrine:migrations:migrate
```

Avant la migration, ajoutez `config/packages/escalated.yaml` et importez les routes du bundle dans `config/routes/escalated.yaml`. Nécessite PHP 8.2+ et Symfony 6.4 ou 7.x. Le [guide de démarrage](../getting-started.md) contient les deux fichiers, les rôles de sécurité et les commandes planifiées.

## En savoir plus

- [Toutes les fonctionnalités](../features.md) · [Premiers pas](../getting-started.md) · [Connexion à la base de données](../configuration.md)
- [Architecture et transitions de statut](../architecture.md) · [Objets de ticket, actions et traductions](../customization.md)
- [Contrôle d'accès](../access-control.md) · [Accès aux tickets et routage](../ticket-access-and-routing.md)
- [Intégrer le widget](../embedding-the-widget.md) · [Accès invité](../guest-access.md)
- [Panneau agent](../agent-panel.md) · [Intégrer l'administration](../embedding-admin.md)
- [Plugins](../plugins.md) · [Newsletters](../newsletters.md)

Escalated est aussi disponible pour [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring), [WordPress](https://github.com/escalated-dev/escalated-wordpress) et [Filament](https://github.com/escalated-dev/escalated-filament). L'interface se trouve dans [escalated](https://github.com/escalated-dev/escalated).

## Licence

MIT. Copyright (c) Escalated.dev. Voir [LICENSE](../../LICENSE).
