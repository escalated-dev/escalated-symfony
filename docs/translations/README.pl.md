<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <a href="README.it.md">Italiano</a> •
  <a href="README.ja.md">日本語</a> •
  <a href="README.ko.md">한국어</a> •
  <a href="README.nl.md">Nederlands</a> •
  <b>Polski</b> •
  <a href="README.pt-BR.md">Português (BR)</a> •
  <a href="README.ru.md">Русский</a> •
  <a href="README.tr.md">Türkçe</a> •
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for Symfony

Osadzalny system zgłoszeń wsparcia dla aplikacji Symfony. Gotowy helpdesk ze zgłoszeniami, odpowiedziami, działami, tagami, politykami SLA i kontrolą dostępu opartą na rolach.

## Wymagania

- PHP 8.2+
- Symfony 6.4 lub 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## Instalacja

```bash
composer require escalated-dev/escalated-symfony
```

### 1. Rejestracja bundle

Jeśli Symfony Flex jest zainstalowany, bundle jest rejestrowany automatycznie. W przeciwnym razie dodaj go do `config/bundles.php`:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. Konfiguracja bundle

Utwórz `config/packages/escalated.yaml`:

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

### 3. Uruchomienie migracji

```bash
php bin/console doctrine:migrations:migrate
```

Migracja tworzy wszystkie wymagane tabele (`escalated_tickets`, `escalated_replies`, `escalated_departments`, `escalated_tags`, `escalated_sla_policies`, `escalated_ticket_activities`, `escalated_agent_profiles`).

### 4. Konfiguracja bezpieczeństwa

Dodaj rolę `ROLE_ESCALATED_ADMIN` do użytkowników administratorów w dostawcy użytkowników. Dostęp agenta jest określany przez obecność encji `AgentProfile` powiązanej z użytkownikiem.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (Opcjonalnie) Instalacja Inertia

Dla wbudowanego interfejsu frontend zainstaluj bundle Inertia:

```bash
composer require rompetomp/inertia-bundle
# lub
composer require skipthedragon/inertia-bundle
```

Ustaw `ui_enabled: false`, jeśli chcesz używać tylko API i usług z niestandardowym frontendem.

## Funkcje

- **Cykl życia zgłoszenia** — Tworzenie, przypisywanie, odpowiadanie, rozwiązywanie, zamykanie, ponowne otwieranie z konfigurowalnymi przejściami statusów
- **Silnik SLA** — Cele odpowiedzi i rozwiązania wg priorytetów, obliczanie godzin pracy, automatyczne wykrywanie naruszeń
- **Panel agentów** — Kolejka zgłoszeń z filtrami, notatkami wewnętrznymi, gotowymi odpowiedziami
- **Portal klienta** — Samoobsługowe tworzenie zgłoszeń, odpowiedzi i śledzenie statusu
- **Panel administracyjny** — Zarządzanie działami, politykami SLA, tagami i przeglądanie raportów
- **Załączniki** — Przesyłanie metodą przeciągnij i upuść z konfigurowalnym przechowywaniem i limitami rozmiaru
- **Oś czasu aktywności** — Pełny dziennik audytu każdego działania na każdym zgłoszeniu
- **Powiadomienia email** — Konfigurowalne powiadomienia wg zdarzeń
- **Routing działowy** — Organizowanie agentów w działy z automatycznym przypisywaniem
- **System tagów** — Kategoryzacja zgłoszeń kolorowymi tagami
- **Podział zgłoszeń** — Podział odpowiedzi na nowe samodzielne zgłoszenie z zachowaniem oryginalnego kontekstu
- **Odkładanie zgłoszeń** — Odkładanie zgłoszeń z presetami (1h, 4h, jutro, przyszły tydzień); komenda `php bin/console escalated:wake-snoozed-tickets` automatycznie je budzi zgodnie z harmonogramem
- **Zapisane widoki / niestandardowe kolejki** — Zapisywanie, nazywanie i udostępnianie presetów filtrów jako wielokrotnego użytku widoków zgłoszeń
- **Osadzalny widget wsparcia** — Lekki widget `<script>` z wyszukiwaniem w bazie wiedzy, formularzem zgłoszenia i sprawdzaniem statusu
- **Wątki email** — Wychodzące emaile zawierają prawidłowe nagłówki `In-Reply-To` i `References` dla poprawnego wątkowania w klientach pocztowych
- **Markowe szablony email** — Konfigurowalne logo, kolor główny i tekst stopki dla wszystkich wychodzących emaili
- **Transmisja w czasie rzeczywistym** — Opcjonalna transmisja przez Mercure z automatycznym fallbackiem na polling
- **Przełącznik bazy wiedzy** — Włączanie lub wyłączanie publicznej bazy wiedzy z ustawień administratora

## Architektura

### Encje

| Encja | Opis |
|---|---|
| `Ticket` | Zgłoszenie wsparcia ze statusem, priorytetem, śledzeniem SLA |
| `Reply` | Publiczna odpowiedź lub wewnętrzna notatka do zgłoszenia |
| `Department` | Grupowanie organizacyjne dla zgłoszeń i agentów |
| `Tag` | Etykiety do kategoryzacji zgłoszeń |
| `SlaPolicy` | Cele czasu pierwszej odpowiedzi i rozwiązania wg priorytetów |
| `TicketActivity` | Dziennik audytu wszystkich zmian w zgłoszeniach |
| `AgentProfile` | Metadane agenta (typ, pojemność) |

### Usługi

| Usługa | Opis |
|---|---|
| `TicketService` | Tworzenie, aktualizacja, przejścia, odpowiadanie na zgłoszenia |
| `AssignmentService` | Przypisywanie/usuwanie agentów, sprawdzanie obciążenia |
| `SlaService` | Dołączanie polityk SLA, sprawdzanie naruszeń |

### Kontrolery

Trasy są zorganizowane w cztery grupy, wszystkie pod skonfigurowanym `route_prefix`:

- **Customer** (`/customer/tickets`) -- CRUD zgłoszeń dla uwierzytelnionych użytkowników końcowych
- **Agent** (`/agent`) -- Panel i zarządzanie zgłoszeniami dla agentów wsparcia
- **Admin** (`/admin`) -- Pełne zarządzanie zgłoszeniami, działami, tagami, ustawieniami
- **API** (`/api/v1`) -- JSON REST API do integracji zewnętrznych

### Bezpieczeństwo

Dwóch voterów Symfony kontroluje dostęp:

- `ESCALATED_AGENT` -- Przyznawany, gdy użytkownik ma rekord `AgentProfile`
- `ESCALATED_ADMIN` -- Przyznawany, gdy użytkownik ma rolę `ROLE_ESCALATED_ADMIN`

### Renderowanie UI

Kontrolery używają `UiRendererInterface` do renderowania stron. Domyślny `InertiaUiRenderer` deleguje do zainstalowanego bundle Inertia. Aby użyć Twig lub innego renderera, zaimplementuj `UiRendererInterface` i nadpisz usługę w konfiguracji kontenera:

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## Przejścia statusów

Zgłoszenia podążają za maszyną stanów z tymi przejściami:

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

## Testowanie

```bash
vendor/bin/phpunit
```

## Licencja

MIT
