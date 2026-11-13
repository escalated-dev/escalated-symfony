<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.it.md">Italiano</a> ·
  <a href="README.ja.md">日本語</a> ·
  <a href="README.ko.md">한국어</a> ·
  <a href="README.nl.md">Nederlands</a> ·
  <a href="README.pl.md">Polski</a> ·
  <a href="README.pt-BR.md">Português (BR)</a> ·
  <b>Русский</b> ·
  <a href="README.tr.md">Türkçe</a> ·
  <a href="README.zh-CN.md">简体中文</a>
</sub></p>

<p align="center">
  <a href="https://escalated.dev"><img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/logo.png" width="72" height="72" alt="Escalated"></a>
</p>

<h1 align="center">Escalated for Symfony</h1>

<p align="center">
  Добавьте полноценный хелпдеск в ваше приложение на Symfony.<br>
  Тикеты, SLA, правила эскалации, входящие для агентов и клиентский портал, на вашей собственной базе данных.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Сайт</b></a> ·
  <a href="https://escalated.dev/docs"><b>Документация</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Список изменений</b></a>
</p>

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-dark.png">
  <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-light.png" alt="Входящие агентов в Escalated: сохранённые представления со счётчиками, обратный отсчёт SLA и приоритет у каждого тикета" width="100%">
</picture>

## Что он делает

<table>
  <tr>
    <td width="40%">
      <h3>Входящие, в которых агенты могут работать весь день</h3>
      Переписка, внутренние заметки, шаблоны ответов и таймеры SLA на одном экране. Тикеты также приходят по электронной почте через Postmark, Mailgun или SES.
    </td>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-light.png" alt="Тикет, открытый в рабочем пространстве агента, с редактором ответа">
      </picture>
    </td>
  </tr>
  <tr>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-light.png" alt="Страница отчётов администратора со временем ответа, CSAT и нарушениями SLA">
      </picture>
    </td>
    <td width="40%">
      <h3>SLA и правила эскалации, которые вы видите</h3>
      Цели по времени ответа и решения для каждого приоритета с учётом рабочих часов, правила, которые эскалируют или переназначают тикет при отставании, и отчёты о нарушениях.
    </td>
  </tr>
</table>

## Возможности

<table>
<tr>
<td width="33%" valign="top">

**Тикеты**
- Создание, назначение, ответ, решение, повторное открытие
- Настраиваемые переходы статусов
- Выделение ответа в новый тикет
- Откладывание с автоматическим возвратом
- Теги, вложения, история активности

</td>
<td width="33%" valign="top">

**Рабочее место агента**
- Очередь с фильтрами и сохранёнными представлениями
- Внутренние заметки и шаблоны ответов
- Отделы с автоматическим назначением
- Обновления в реальном времени через Mercure

</td>
<td width="33%" valign="top">

**SLA и эскалация**
- Цели по времени ответа и решения для каждого приоритета
- Рабочие часы
- Автоматическое обнаружение нарушений
- Правила эскалации и автоматизации

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Клиенты**
- Портал самообслуживания: создать, ответить, отследить
- Проверенный гостевой доступ по коду из письма
- Встраиваемый виджет с поиском по базе знаний
- Ограничение частоты запросов на гостевых эндпоинтах
- База знаний, включается и отключается

</td>
<td width="33%" valign="top">

**Электронная почта**
- Входящие через Postmark, Mailgun, SES
- Уведомления по отдельным событиям
- Правильные цепочки писем в почтовых клиентах
- Шаблоны с вашим брендингом

</td>
<td width="33%" valign="top">

**Администрирование и доступ**
- Отделы, политики SLA, теги, отчёты
- Роли, права, двухфакторная аутентификация, SSO
- Администрирование внутри вашей собственной админ-панели
- Исходящие вебхуки и плагины

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Все возможности подробно</a></sub></p>

<p align="center">
  <a href="https://symfony.com"><img src="https://img.shields.io/badge/Symfony_6.4_or_7-000000?style=for-the-badge&logo=symfony&logoColor=white" alt="Symfony 6.4 or 7"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP_8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://www.doctrine-project.org"><img src="https://img.shields.io/badge/Doctrine-FC6A31?style=for-the-badge&logo=doctrine&logoColor=white" alt="Doctrine"></a>
  <a href="https://inertiajs.com"><img src="https://img.shields.io/badge/Inertia-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia"></a>
  <a href="https://vuejs.org"><img src="https://img.shields.io/badge/Vue_3-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue 3"></a>
</p>

## Быстрый старт

```bash
composer require escalated-dev/escalated-symfony
composer require rompetomp/inertia-bundle
php bin/console doctrine:migrations:migrate
```

Перед миграцией добавьте `config/packages/escalated.yaml` и импортируйте маршруты бандла в `config/routes/escalated.yaml`. Нужны PHP 8.2+ и Symfony 6.4 или 7.x. В [руководстве по началу работы](../getting-started.md) есть оба файла, роли безопасности и запланированные команды.

## Подробнее

- [Все возможности](../features.md) · [Начало работы](../getting-started.md) · [Подключение к базе данных](../configuration.md)
- [Архитектура и переходы статусов](../architecture.md) · [Темы тикетов, действия и переводы](../customization.md)
- [Управление доступом](../access-control.md) · [Доступ к тикетам и маршрутизация](../ticket-access-and-routing.md)
- [Встраивание виджета](../embedding-the-widget.md) · [Гостевой доступ](../guest-access.md)
- [Панель агента](../agent-panel.md) · [Встраивание админки](../embedding-admin.md)
- [Плагины](../plugins.md) · [Рассылки](../newsletters.md)

Escalated также доступен для [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring), [WordPress](https://github.com/escalated-dev/escalated-wordpress) и [Filament](https://github.com/escalated-dev/escalated-filament). Интерфейс находится в [escalated](https://github.com/escalated-dev/escalated).

## Лицензия

MIT. Copyright (c) Escalated.dev. См. [LICENSE](../../LICENSE).
