<p align="center">
  <a href="README.ar.md">العربية</a> •
  <b>Deutsch</b> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
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

Ein integrierbares Support-Ticketsystem für Symfony-Anwendungen. Plug-and-Play-Helpdesk mit Tickets, Antworten, Abteilungen, Tags, SLA-Richtlinien und rollenbasierter Zugriffskontrolle.

## Voraussetzungen

- PHP 8.2+
- Symfony 6.4 oder 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## Installation

```bash
composer require escalated-dev/escalated-symfony
```

### 1. Bundle registrieren

Wenn Symfony Flex installiert ist, wird das Bundle automatisch registriert. Andernfalls fügen Sie es zu `config/bundles.php` hinzu:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. Bundle konfigurieren

Erstellen Sie `config/packages/escalated.yaml`:

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

### 3. Migrationen ausführen

```bash
php bin/console doctrine:migrations:migrate
```

Die Migration erstellt alle erforderlichen Tabellen (`escalated_tickets`, `escalated_replies`, `escalated_departments`, `escalated_tags`, `escalated_sla_policies`, `escalated_ticket_activities`, `escalated_agent_profiles`).

### 4. Sicherheit einrichten

Fügen Sie die Rolle `ROLE_ESCALATED_ADMIN` zu Admin-Benutzern in Ihrem Benutzer-Provider hinzu. Der Agentenzugang wird durch das Vorhandensein einer mit dem Benutzer verknüpften `AgentProfile`-Entität bestimmt.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (Optional) Inertia installieren

Für die integrierte Frontend-Oberfläche installieren Sie ein Inertia-Bundle:

```bash
composer require rompetomp/inertia-bundle
# oder
composer require skipthedragon/inertia-bundle
```

Setzen Sie `ui_enabled: false`, wenn Sie nur die API und Services mit einem eigenen Frontend nutzen möchten.

## Funktionen

- **Ticket-Lebenszyklus** — Erstellen, zuweisen, antworten, lösen, schließen, wiedereröffnen mit konfigurierbaren Statusübergängen
- **SLA-Engine** — Antwort- und Lösungsziele pro Priorität, Geschäftszeitenberechnung, automatische Verletzungserkennung
- **Agenten-Dashboard** — Ticketwarteschlange mit Filtern, internen Notizen, vorgefertigten Antworten
- **Kundenportal** — Self-Service-Ticketerstellung, Antworten und Statusverfolgung
- **Admin-Panel** — Abteilungen, SLA-Richtlinien, Tags verwalten und Berichte anzeigen
- **Dateianhänge** — Drag-and-Drop-Uploads mit konfigurierbarem Speicher und Größenlimits
- **Aktivitäts-Timeline** — Vollständiges Audit-Log jeder Aktion an jedem Ticket
- **E-Mail-Benachrichtigungen** — Konfigurierbare Benachrichtigungen pro Ereignis
- **Abteilungs-Routing** — Agenten in Abteilungen organisieren mit automatischer Zuweisung
- **Tag-System** — Tickets mit farbigen Tags kategorisieren
- **Ticket-Aufteilen** — Eine Antwort in ein neues eigenständiges Ticket aufteilen und den ursprünglichen Kontext bewahren
- **Ticket-Schlummern** — Tickets mit Presets schlummern lassen (1h, 4h, morgen, nächste Woche); der Konsolenbefehl `php bin/console escalated:wake-snoozed-tickets` weckt sie automatisch nach Zeitplan
- **Gespeicherte Ansichten / benutzerdefinierte Warteschlangen** — Filtervoreinstellungen als wiederverwendbare Ticketansichten speichern, benennen und teilen
- **Einbettbares Support-Widget** — Leichtgewichtiges `<script>`-Widget mit KB-Suche, Ticketformular und Statusprüfung
- **E-Mail-Threading** — Ausgehende E-Mails enthalten korrekte `In-Reply-To`- und `References`-Header für richtiges Threading in E-Mail-Clients
- **Gebrandete E-Mail-Vorlagen** — Konfigurierbares Logo, Primärfarbe und Fußzeilentext für alle ausgehenden E-Mails
- **Echtzeit-Broadcasting** — Opt-in-Broadcasting über Mercure mit automatischem Polling-Fallback
- **Wissensdatenbank-Schalter** — Öffentliche Wissensdatenbank in den Admin-Einstellungen aktivieren oder deaktivieren

## Architektur

### Entitäten

| Entität | Beschreibung |
|---|---|
| `Ticket` | Support-Ticket mit Status, Priorität, SLA-Tracking |
| `Reply` | Öffentliche Antwort oder interne Notiz an einem Ticket |
| `Department` | Organisatorische Gruppierung für Tickets und Agenten |
| `Tag` | Bezeichnungen zur Kategorisierung von Tickets |
| `SlaPolicy` | Erstantwort- und Lösungszeitziele pro Priorität |
| `TicketActivity` | Audit-Log aller Ticketänderungen |
| `AgentProfile` | Agenten-Metadaten (Typ, Kapazität) |

### Services

| Service | Beschreibung |
|---|---|
| `TicketService` | Tickets erstellen, aktualisieren, überführen, beantworten |
| `AssignmentService` | Agenten zuweisen/entziehen, Arbeitslast prüfen |
| `SlaService` | SLA-Richtlinien anhängen, auf Verletzungen prüfen |

### Controller

Routen sind in vier Gruppen organisiert, alle unter dem konfigurierten `route_prefix`:

- **Customer** (`/customer/tickets`) -- Ticket-CRUD für authentifizierte Endbenutzer
- **Agent** (`/agent`) -- Dashboard und Ticketverwaltung für Support-Agenten
- **Admin** (`/admin`) -- Vollständige Verwaltung von Tickets, Abteilungen, Tags, Einstellungen
- **API** (`/api/v1`) -- JSON REST API für externe Integrationen

### Sicherheit

Zwei Symfony-Voters steuern den Zugang:

- `ESCALATED_AGENT` -- Gewährt, wenn der Benutzer einen `AgentProfile`-Datensatz hat
- `ESCALATED_ADMIN` -- Gewährt, wenn der Benutzer die Rolle `ROLE_ESCALATED_ADMIN` hat

### UI-Rendering

Controller verwenden `UiRendererInterface` zum Rendern von Seiten. Der Standard-`InertiaUiRenderer` delegiert an das installierte Inertia-Bundle. Um Twig oder einen anderen Renderer zu verwenden, implementieren Sie `UiRendererInterface` und überschreiben Sie den Service in Ihrer Container-Konfiguration:

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## Statusübergänge

Tickets folgen einer Zustandsmaschine mit diesen Übergängen:

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

## Testen

```bash
vendor/bin/phpunit
```

## Lizenz

MIT
