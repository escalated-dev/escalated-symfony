<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <b>Italiano</b> •
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

Un sistema di ticket di supporto integrabile per applicazioni Symfony. Helpdesk plug-and-play con ticket, risposte, dipartimenti, tag, politiche SLA e controllo degli accessi basato sui ruoli.

## Requisiti

- PHP 8.2+
- Symfony 6.4 o 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## Installazione

```bash
composer require escalated-dev/escalated-symfony
```

### 1. Registrare il bundle

Se Symfony Flex è installato, il bundle viene registrato automaticamente. Altrimenti, aggiungilo a `config/bundles.php`:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. Configurare il bundle

Crea `config/packages/escalated.yaml`:

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

### 3. Eseguire le migrazioni

```bash
php bin/console doctrine:migrations:migrate
```

La migrazione crea tutte le tabelle necessarie (`escalated_tickets`, `escalated_replies`, `escalated_departments`, `escalated_tags`, `escalated_sla_policies`, `escalated_ticket_activities`, `escalated_agent_profiles`).

### 4. Configurare la sicurezza

Aggiungi il ruolo `ROLE_ESCALATED_ADMIN` agli utenti amministratori nel tuo provider utenti. L'accesso agente è determinato dalla presenza di un'entità `AgentProfile` collegata all'utente.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (Opzionale) Installare Inertia

Per l'interfaccia frontend integrata, installa un bundle Inertia:

```bash
composer require rompetomp/inertia-bundle
# oppure
composer require skipthedragon/inertia-bundle
```

Imposta `ui_enabled: false` se vuoi utilizzare solo l'API e i servizi con un frontend personalizzato.

## Funzionalità

- **Ciclo di vita del ticket** — Creare, assegnare, rispondere, risolvere, chiudere, riaprire con transizioni di stato configurabili
- **Motore SLA** — Obiettivi di risposta e risoluzione per priorità, calcolo delle ore lavorative, rilevamento automatico delle violazioni
- **Dashboard agenti** — Coda ticket con filtri, note interne, risposte predefinite
- **Portale clienti** — Creazione ticket self-service, risposte e monitoraggio dello stato
- **Pannello di amministrazione** — Gestire dipartimenti, politiche SLA, tag e visualizzare report
- **Allegati** — Upload drag-and-drop con archiviazione e limiti di dimensione configurabili
- **Timeline attività** — Log di audit completo di ogni azione su ogni ticket
- **Notifiche email** — Notifiche configurabili per evento
- **Instradamento per dipartimento** — Organizzare gli agenti in dipartimenti con assegnazione automatica
- **Sistema di tag** — Categorizzare i ticket con tag colorati
- **Divisione ticket** — Dividere una risposta in un nuovo ticket autonomo preservando il contesto originale
- **Sospensione ticket** — Sospendere ticket con preset (1h, 4h, domani, prossima settimana); il comando `php bin/console escalated:wake-snoozed-tickets` li riattiva automaticamente secondo programmazione
- **Viste salvate / code personalizzate** — Salvare, nominare e condividere preset di filtri come viste ticket riutilizzabili
- **Widget di supporto integrabile** — Widget leggero `<script>` con ricerca KB, modulo ticket e verifica stato
- **Threading email** — Le email in uscita includono intestazioni `In-Reply-To` e `References` appropriate per un corretto threading nei client email
- **Template email personalizzati** — Logo, colore primario e testo a piè di pagina configurabili per tutte le email in uscita
- **Broadcasting in tempo reale** — Broadcasting opzionale via Mercure con fallback automatico su polling
- **Interruttore knowledge base** — Abilitare o disabilitare la knowledge base pubblica dalle impostazioni di amministrazione

## Architettura

### Entità

| Entità | Descrizione |
|---|---|
| `Ticket` | Ticket di supporto con stato, priorità, tracciamento SLA |
| `Reply` | Risposta pubblica o nota interna su un ticket |
| `Department` | Raggruppamento organizzativo per ticket e agenti |
| `Tag` | Etichette per categorizzare i ticket |
| `SlaPolicy` | Obiettivi di tempo di prima risposta e risoluzione per priorità |
| `TicketActivity` | Log di audit di tutti i cambiamenti dei ticket |
| `AgentProfile` | Metadati dell'agente (tipo, capacità) |

### Servizi

| Servizio | Descrizione |
|---|---|
| `TicketService` | Creare, aggiornare, transizionare, rispondere ai ticket |
| `AssignmentService` | Assegnare/rimuovere agenti, verificare il carico di lavoro |
| `SlaService` | Collegare politiche SLA, verificare le violazioni |

### Controller

Le route sono organizzate in quattro gruppi, tutti sotto il `route_prefix` configurato:

- **Customer** (`/customer/tickets`) -- CRUD ticket per utenti finali autenticati
- **Agent** (`/agent`) -- Dashboard e gestione ticket per agenti di supporto
- **Admin** (`/admin`) -- Gestione completa di ticket, dipartimenti, tag, impostazioni
- **API** (`/api/v1`) -- JSON REST API per integrazioni esterne

### Sicurezza

Due voter Symfony controllano l'accesso:

- `ESCALATED_AGENT` -- Concesso quando l'utente ha un record `AgentProfile`
- `ESCALATED_ADMIN` -- Concesso quando l'utente ha il ruolo `ROLE_ESCALATED_ADMIN`

### Rendering UI

I controller usano `UiRendererInterface` per il rendering delle pagine. Il `InertiaUiRenderer` predefinito delega al bundle Inertia installato. Per usare Twig o un altro renderer, implementa `UiRendererInterface` e sovrascrivi il servizio nella configurazione del container:

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## Transizioni di stato

I ticket seguono una macchina a stati con queste transizioni:

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

## Test

```bash
vendor/bin/phpunit
```

## Licenza

MIT
