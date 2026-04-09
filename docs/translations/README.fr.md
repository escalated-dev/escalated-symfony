<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <b>Français</b> •
  <a href="README.it.md">Italiano</a> •
  <a href="README.ja.md">日本語</a> •
  <a href="README.ko.md">한국어</a> •
  <a href="README.nl.md">Nederlands</a> •
  <a href="README.pl.md">Polski</a> •
  <a href="README.pt-BR.md">Português (BR)</a> •
  <a href="README.ru.md">Русский</a> •
  <a href="README.tr.md">Türkçe</a> •
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for Symfony

Un système de tickets de support intégrable pour les applications Symfony. Helpdesk clé en main avec tickets, réponses, départements, tags, politiques SLA et contrôle d'accès basé sur les rôles.

## Prérequis

- PHP 8.2+
- Symfony 6.4 ou 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## Installation

```bash
composer require escalated-dev/escalated-symfony
```

### 1. Enregistrer le bundle

Si Symfony Flex est installé, le bundle est enregistré automatiquement. Sinon, ajoutez-le à `config/bundles.php` :

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. Configurer le bundle

Créez `config/packages/escalated.yaml` :

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

### 3. Exécuter les migrations

```bash
php bin/console doctrine:migrations:migrate
```

La migration crée toutes les tables nécessaires (`escalated_tickets`, `escalated_replies`, `escalated_departments`, `escalated_tags`, `escalated_sla_policies`, `escalated_ticket_activities`, `escalated_agent_profiles`).

### 4. Configurer la sécurité

Ajoutez le rôle `ROLE_ESCALATED_ADMIN` aux utilisateurs administrateurs dans votre fournisseur d'utilisateurs. L'accès agent est déterminé par la présence d'une entité `AgentProfile` liée à l'utilisateur.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (Optionnel) Installer Inertia

Pour l'interface frontend intégrée, installez un bundle Inertia :

```bash
composer require rompetomp/inertia-bundle
# ou
composer require skipthedragon/inertia-bundle
```

Définissez `ui_enabled: false` si vous souhaitez utiliser uniquement l'API et les services avec un frontend personnalisé.

## Fonctionnalités

- **Cycle de vie du ticket** — Créer, assigner, répondre, résoudre, fermer, rouvrir avec des transitions de statut configurables
- **Moteur SLA** — Objectifs de réponse et résolution par priorité, calcul des heures ouvrables, détection automatique des violations
- **Tableau de bord agent** — File d'attente des tickets avec filtres, notes internes, réponses pré-enregistrées
- **Portail client** — Création de tickets en libre-service, réponses et suivi de statut
- **Panneau d'administration** — Gérer les départements, politiques SLA, tags et consulter les rapports
- **Pièces jointes** — Téléchargement par glisser-déposer avec stockage et limites de taille configurables
- **Chronologie d'activité** — Journal d'audit complet de chaque action sur chaque ticket
- **Notifications par e-mail** — Notifications configurables par événement
- **Routage par département** — Organiser les agents en départements avec assignation automatique
- **Système de tags** — Catégoriser les tickets avec des tags colorés
- **Division de tickets** — Diviser une réponse en un nouveau ticket autonome tout en préservant le contexte original
- **Mise en veille de tickets** — Mettre en veille des tickets avec des préréglages (1h, 4h, demain, semaine prochaine) ; la commande `php bin/console escalated:wake-snoozed-tickets` les réveille automatiquement selon le planning
- **Vues sauvegardées / files personnalisées** — Sauvegarder, nommer et partager des préréglages de filtres comme vues de tickets réutilisables
- **Widget de support intégrable** — Widget léger `<script>` avec recherche dans la base de connaissances, formulaire de ticket et vérification de statut
- **Threading d'e-mails** — Les e-mails sortants incluent les en-têtes `In-Reply-To` et `References` appropriés pour un fil de discussion correct dans les clients mail
- **Modèles d'e-mails personnalisés** — Logo, couleur primaire et texte de pied de page configurables pour tous les e-mails sortants
- **Diffusion en temps réel** — Diffusion optionnelle via Mercure avec repli automatique sur le polling
- **Interrupteur base de connaissances** — Activer ou désactiver la base de connaissances publique depuis les paramètres d'administration

## Architecture

### Entités

| Entité | Description |
|---|---|
| `Ticket` | Ticket de support avec statut, priorité, suivi SLA |
| `Reply` | Réponse publique ou note interne sur un ticket |
| `Department` | Regroupement organisationnel pour les tickets et agents |
| `Tag` | Libellés pour catégoriser les tickets |
| `SlaPolicy` | Objectifs de temps de première réponse et résolution par priorité |
| `TicketActivity` | Journal d'audit de tous les changements de tickets |
| `AgentProfile` | Métadonnées de l'agent (type, capacité) |

### Services

| Service | Description |
|---|---|
| `TicketService` | Créer, mettre à jour, transitionner, répondre aux tickets |
| `AssignmentService` | Assigner/désassigner des agents, vérifier la charge de travail |
| `SlaService` | Attacher des politiques SLA, vérifier les violations |

### Contrôleurs

Les routes sont organisées en quatre groupes, tous sous le `route_prefix` configuré :

- **Customer** (`/customer/tickets`) -- CRUD des tickets pour les utilisateurs finaux authentifiés
- **Agent** (`/agent`) -- Tableau de bord et gestion des tickets pour les agents de support
- **Admin** (`/admin`) -- Gestion complète des tickets, départements, tags, paramètres
- **API** (`/api/v1`) -- JSON REST API pour les intégrations externes

### Sécurité

Deux voters Symfony contrôlent l'accès :

- `ESCALATED_AGENT` -- Accordé lorsque l'utilisateur a un enregistrement `AgentProfile`
- `ESCALATED_ADMIN` -- Accordé lorsque l'utilisateur a le rôle `ROLE_ESCALATED_ADMIN`

### Rendu UI

Les contrôleurs utilisent `UiRendererInterface` pour le rendu des pages. Le `InertiaUiRenderer` par défaut délègue au bundle Inertia installé. Pour utiliser Twig ou un autre moteur de rendu, implémentez `UiRendererInterface` et remplacez le service dans la configuration de votre conteneur :

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## Transitions de statut

Les tickets suivent une machine à états avec ces transitions :

```
open -> in_progress, waiting_on_customer, waiting_on_agent, escalated, resolved, closed
in_progress -> waiting_on_customer, waiting_on_agent, escalated, resolved, closed
waiting_on_customer -> open, in_progress, resolved, closed
waiting_on_agent -> open, in_progress, escalated, resolved, closed
escalated -> in_progress, resolved, closed
resolved -> reopened, closed
closed -> reopened
reopened -> in_progress, waiting_on_customer, waiting_on_agent, escalated, resolved, closed
```

## Tests

```bash
vendor/bin/phpunit
```

## Licence

MIT
