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
  <a href="README.pl.md">Polski</a> •
  <a href="README.pt-BR.md">Português (BR)</a> •
  <b>Русский</b> •
  <a href="README.tr.md">Türkçe</a> •
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for Symfony

Встраиваемая система тикетов поддержки для приложений Symfony. Готовый helpdesk с тикетами, ответами, отделами, тегами, политиками SLA и управлением доступом на основе ролей.

## Требования

- PHP 8.2+
- Symfony 6.4 или 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## Установка

```bash
composer require escalated-dev/escalated-symfony
```

### 1. Регистрация бандла

Если установлен Symfony Flex, бандл регистрируется автоматически. В противном случае добавьте его в `config/bundles.php`:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. Настройка бандла

Создайте `config/packages/escalated.yaml`:

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

### 3. Выполнение миграций

```bash
php bin/console doctrine:migrations:migrate
```

Миграция создаёт все необходимые таблицы (`escalated_tickets`, `escalated_replies`, `escalated_departments`, `escalated_tags`, `escalated_sla_policies`, `escalated_ticket_activities`, `escalated_agent_profiles`).

### 4. Настройка безопасности

Добавьте роль `ROLE_ESCALATED_ADMIN` пользователям-администраторам в вашем провайдере пользователей. Доступ агентов определяется наличием сущности `AgentProfile`, связанной с пользователем.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (Необязательно) Установка Inertia

Для встроенного фронтенд-интерфейса установите бандл Inertia:

```bash
composer require rompetomp/inertia-bundle
# или
composer require skipthedragon/inertia-bundle
```

Установите `ui_enabled: false`, если хотите использовать только API и сервисы с собственным фронтендом.

## Возможности

- **Жизненный цикл тикета** — Создание, назначение, ответ, решение, закрытие, повторное открытие с настраиваемыми переходами статусов
- **SLA-движок** — Цели ответа и решения по приоритетам, расчёт рабочих часов, автоматическое обнаружение нарушений
- **Панель агентов** — Очередь тикетов с фильтрами, внутренними заметками, шаблонными ответами
- **Портал клиентов** — Самообслуживание: создание тикетов, ответы и отслеживание статуса
- **Панель администратора** — Управление отделами, политиками SLA, тегами и просмотр отчётов
- **Вложения файлов** — Загрузка перетаскиванием с настраиваемым хранилищем и лимитами размера
- **Хронология активности** — Полный журнал аудита каждого действия по каждому тикету
- **Уведомления по email** — Настраиваемые уведомления по событиям
- **Маршрутизация по отделам** — Организация агентов по отделам с автоназначением
- **Система тегов** — Категоризация тикетов цветными тегами
- **Разделение тикетов** — Разделение ответа в новый самостоятельный тикет с сохранением оригинального контекста
- **Отложение тикетов** — Откладывание тикетов с пресетами (1ч, 4ч, завтра, следующая неделя); команда `php bin/console escalated:wake-snoozed-tickets` автоматически пробуждает их по расписанию
- **Сохранённые представления / пользовательские очереди** — Сохранение, именование и совместное использование пресетов фильтров как повторно используемых представлений тикетов
- **Встраиваемый виджет поддержки** — Лёгкий виджет `<script>` с поиском по базе знаний, формой тикета и проверкой статуса
- **Потоки email** — Исходящие письма включают корректные заголовки `In-Reply-To` и `References` для правильной группировки в почтовых клиентах
- **Брендированные шаблоны email** — Настраиваемый логотип, основной цвет и текст подвала для всех исходящих писем
- **Трансляция в реальном времени** — Опциональная трансляция через Mercure с автоматическим откатом на polling
- **Переключатель базы знаний** — Включение или отключение публичной базы знаний из настроек администратора

## Архитектура

### Сущности

| Сущность | Описание |
|---|---|
| `Ticket` | Тикет поддержки со статусом, приоритетом, отслеживанием SLA |
| `Reply` | Публичный ответ или внутренняя заметка к тикету |
| `Department` | Организационная группировка для тикетов и агентов |
| `Tag` | Метки для категоризации тикетов |
| `SlaPolicy` | Цели времени первого ответа и решения по приоритетам |
| `TicketActivity` | Журнал аудита всех изменений тикетов |
| `AgentProfile` | Метаданные агента (тип, ёмкость) |

### Сервисы

| Сервис | Описание |
|---|---|
| `TicketService` | Создание, обновление, переход, ответ на тикеты |
| `AssignmentService` | Назначение/снятие агентов, проверка нагрузки |
| `SlaService` | Привязка политик SLA, проверка нарушений |

### Контроллеры

Маршруты организованы в четыре группы, все под настроенным `route_prefix`:

- **Customer** (`/customer/tickets`) -- CRUD тикетов для аутентифицированных конечных пользователей
- **Agent** (`/agent`) -- Панель управления и управление тикетами для агентов поддержки
- **Admin** (`/admin`) -- Полное управление тикетами, отделами, тегами, настройками
- **API** (`/api/v1`) -- JSON REST API для внешних интеграций

### Безопасность

Два voter Symfony контролируют доступ:

- `ESCALATED_AGENT` -- Предоставляется, когда у пользователя есть запись `AgentProfile`
- `ESCALATED_ADMIN` -- Предоставляется, когда у пользователя есть роль `ROLE_ESCALATED_ADMIN`

### Рендеринг UI

Контроллеры используют `UiRendererInterface` для рендеринга страниц. `InertiaUiRenderer` по умолчанию делегирует установленному бандлу Inertia. Чтобы использовать Twig или другой рендерер, реализуйте `UiRendererInterface` и переопределите сервис в конфигурации контейнера:

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## Переходы статусов

Тикеты следуют конечному автомату с этими переходами:

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

## Тестирование

```bash
vendor/bin/phpunit
```

## Лицензия

MIT
