<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <a href="README.it.md">Italiano</a> •
  <a href="README.ja.md">日本語</a> •
  <a href="README.ko.md">한국어</a> •
  <b>Nederlands</b> •
  <a href="README.pl.md">Polski</a> •
  <a href="README.pt-BR.md">Português (BR)</a> •
  <a href="README.ru.md">Русский</a> •
  <a href="README.tr.md">Türkçe</a> •
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for Symfony

Een inbedbaar ticketsysteem voor ondersteuning voor Symfony-applicaties. Plug-and-play helpdesk met tickets, antwoorden, afdelingen, tags, SLA-beleid en rolgebaseerde toegangscontrole.

## Vereisten

- PHP 8.2+
- Symfony 6.4 of 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## Installatie

```bash
composer require escalated-dev/escalated-symfony
```

### 1. Bundle registreren

Als Symfony Flex is geïnstalleerd, wordt de bundle automatisch geregistreerd. Anders voegt u het toe aan `config/bundles.php`:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. Bundle configureren

Maak `config/packages/escalated.yaml`:

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

### 3. Migraties uitvoeren

```bash
php bin/console doctrine:migrations:migrate
```

De migratie maakt alle benodigde tabellen aan (`escalated_tickets`, `escalated_replies`, `escalated_departments`, `escalated_tags`, `escalated_sla_policies`, `escalated_ticket_activities`, `escalated_agent_profiles`).

### 4. Beveiliging instellen

Voeg de rol `ROLE_ESCALATED_ADMIN` toe aan admin-gebruikers in uw gebruikersprovider. Agenttoegang wordt bepaald door de aanwezigheid van een `AgentProfile`-entiteit gekoppeld aan de gebruiker.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (Optioneel) Inertia installeren

Voor de ingebouwde frontend-UI installeert u een Inertia-bundle:

```bash
composer require rompetomp/inertia-bundle
# of
composer require skipthedragon/inertia-bundle
```

Stel `ui_enabled: false` in als u alleen de API en services wilt gebruiken met een aangepaste frontend.

## Functies

- **Ticketlevenscyclus** — Aanmaken, toewijzen, beantwoorden, oplossen, sluiten, heropenen met configureerbare statusovergangen
- **SLA-engine** — Reactie- en oplossingsdoelen per prioriteit, berekening van kantooruren, automatische schendingsdetectie
- **Agentendashboard** — Ticketwachtrij met filters, interne notities, standaardantwoorden
- **Klantenportaal** — Zelfbediening ticketaanmaak, antwoorden en statusopvolging
- **Adminpaneel** — Afdelingen, SLA-beleid, tags beheren en rapporten bekijken
- **Bestandsbijlagen** — Drag-and-drop uploads met configureerbare opslag en groottelimieten
- **Activiteitstijdlijn** — Volledig auditlogboek van elke actie op elk ticket
- **E-mailmeldingen** — Configureerbare meldingen per gebeurtenis
- **Afdelingsroutering** — Agenten organiseren in afdelingen met automatische toewijzing
- **Tagsysteem** — Tickets categoriseren met gekleurde tags
- **Ticket splitsen** — Een antwoord splitsen in een nieuw zelfstandig ticket met behoud van de originele context
- **Ticket snoozen** — Tickets snoozen met presets (1u, 4u, morgen, volgende week); het `php bin/console escalated:wake-snoozed-tickets` commando wekt ze automatisch volgens schema
- **Opgeslagen weergaven / aangepaste wachtrijen** — Filterpresets opslaan, benoemen en delen als herbruikbare ticketweergaven
- **Inbedbare ondersteuningswidget** — Lichtgewicht `<script>`-widget met KB-zoeken, ticketformulier en statuscontrole
- **E-mailthreading** — Uitgaande e-mails bevatten correcte `In-Reply-To`- en `References`-headers voor juiste threading in e-mailclients
- **Merkgebonden e-mailsjablonen** — Configureerbaar logo, primaire kleur en voettekst voor alle uitgaande e-mails
- **Realtime-uitzending** — Opt-in uitzending via Mercure met automatische polling-terugval
- **Kennisbank-schakelaar** — Publieke kennisbank in- of uitschakelen via admin-instellingen

## Architectuur

### Entiteiten

| Entiteit | Beschrijving |
|---|---|
| `Ticket` | Ondersteuningsticket met status, prioriteit, SLA-tracking |
| `Reply` | Openbaar antwoord of interne notitie op een ticket |
| `Department` | Organisatorische groepering voor tickets en agenten |
| `Tag` | Labels voor het categoriseren van tickets |
| `SlaPolicy` | Doelen voor eerste reactietijd en oplossingstijd per prioriteit |
| `TicketActivity` | Auditlog van alle ticketwijzigingen |
| `AgentProfile` | Agentmetadata (type, capaciteit) |

### Services

| Service | Beschrijving |
|---|---|
| `TicketService` | Tickets aanmaken, bijwerken, overgaan, beantwoorden |
| `AssignmentService` | Agenten toewijzen/onttoewijzen, werklast controleren |
| `SlaService` | SLA-beleid koppelen, controleren op schendingen |

### Controllers

Routes zijn georganiseerd in vier groepen, allemaal onder de geconfigureerde `route_prefix`:

- **Customer** (`/customer/tickets`) -- Ticket-CRUD voor geauthenticeerde eindgebruikers
- **Agent** (`/agent`) -- Dashboard en ticketbeheer voor ondersteuningsagenten
- **Admin** (`/admin`) -- Volledig beheer van tickets, afdelingen, tags, instellingen
- **API** (`/api/v1`) -- JSON REST API voor externe integraties

### Beveiliging

Twee Symfony-voters beheren de toegang:

- `ESCALATED_AGENT` -- Verleend wanneer de gebruiker een `AgentProfile`-record heeft
- `ESCALATED_ADMIN` -- Verleend wanneer de gebruiker de rol `ROLE_ESCALATED_ADMIN` heeft

### UI-rendering

Controllers gebruiken `UiRendererInterface` om pagina's te renderen. De standaard `InertiaUiRenderer` delegeert naar de geïnstalleerde Inertia-bundle. Om Twig of een andere renderer te gebruiken, implementeer `UiRendererInterface` en overschrijf de service in uw containerconfiguratie:

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## Statusovergangen

Tickets volgen een statusmachine met deze overgangen:

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

## Licentie

MIT
