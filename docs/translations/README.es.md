<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <b>Español</b> ·
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
  Añade un helpdesk completo a tu aplicación Symfony.<br>
  Tickets, SLA, reglas de escalado, una bandeja de entrada para agentes y un portal de clientes, funcionando en tu propia base de datos.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Sitio web</b></a> ·
  <a href="https://escalated.dev/docs"><b>Documentación</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Registro de cambios</b></a>
</p>

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-dark.png">
  <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-light.png" alt="La bandeja de entrada de agentes de Escalated: vistas guardadas con contadores, cuentas atrás de SLA y prioridades en cada ticket" width="100%">
</picture>

## Qué hace

<table>
  <tr>
    <td width="40%">
      <h3>Una bandeja de entrada en la que tus agentes pueden trabajar todo el día</h3>
      Conversación, notas internas, respuestas predefinidas y temporizadores de SLA en una sola pantalla. Los tickets también llegan por correo electrónico a través de Postmark, Mailgun o SES.
    </td>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-light.png" alt="Un ticket abierto en el espacio de trabajo del agente con el editor de respuestas">
      </picture>
    </td>
  </tr>
  <tr>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-light.png" alt="La página de informes del administrador con tiempos de respuesta, CSAT e incumplimientos de SLA">
      </picture>
    </td>
    <td width="40%">
      <h3>SLA y reglas de escalado que puedes ver</h3>
      Objetivos de respuesta y resolución por prioridad con horario laboral, reglas que escalan o reasignan cuando no se cumplen e informes sobre incumplimientos.
    </td>
  </tr>
</table>

## Funciones

<table>
<tr>
<td width="33%" valign="top">

**Tickets**
- Crear, asignar, responder, resolver, reabrir
- Transiciones de estado configurables
- Dividir una respuesta en un ticket nuevo
- Posponer con reactivación automática
- Etiquetas, adjuntos, historial de actividad

</td>
<td width="33%" valign="top">

**Espacio de trabajo del agente**
- Cola con filtros y vistas guardadas
- Notas internas y respuestas predefinidas
- Departamentos con asignación automática
- Actualizaciones en tiempo real con Mercure

</td>
<td width="33%" valign="top">

**SLA y escalado**
- Objetivos de respuesta y resolución por prioridad
- Horario laboral
- Detección automática de incumplimientos
- Reglas de escalado y automatizaciones

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Clientes**
- Portal de autoservicio: abrir, responder, seguir
- Acceso de invitados verificado con un código por correo
- Widget integrable con búsqueda en la base de conocimiento
- Límites de peticiones en los endpoints de invitados
- Base de conocimiento, activada o desactivada

</td>
<td width="33%" valign="top">

**Correo electrónico**
- Entrada vía Postmark, Mailgun, SES
- Notificaciones por evento
- Hilos correctos en los clientes de correo
- Plantillas con tu marca

</td>
<td width="33%" valign="top">

**Administración y acceso**
- Departamentos, políticas de SLA, etiquetas, informes
- Roles, permisos, doble factor, SSO
- Administración dentro de tu propio panel de administración
- Webhooks salientes y plugins

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Todas las funciones en detalle</a></sub></p>

<p align="center">
  <a href="https://symfony.com"><img src="https://img.shields.io/badge/Symfony_6.4_or_7-000000?style=for-the-badge&logo=symfony&logoColor=white" alt="Symfony 6.4 or 7"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP_8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://www.doctrine-project.org"><img src="https://img.shields.io/badge/Doctrine-FC6A31?style=for-the-badge&logo=doctrine&logoColor=white" alt="Doctrine"></a>
  <a href="https://inertiajs.com"><img src="https://img.shields.io/badge/Inertia-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia"></a>
  <a href="https://vuejs.org"><img src="https://img.shields.io/badge/Vue_3-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue 3"></a>
</p>

## Inicio rápido

```bash
composer require escalated-dev/escalated-symfony
composer require rompetomp/inertia-bundle
php bin/console doctrine:migrations:migrate
```

Antes de migrar, añade `config/packages/escalated.yaml` e importa las rutas del bundle en `config/routes/escalated.yaml`. Requiere PHP 8.2+ y Symfony 6.4 o 7.x. La [guía de inicio](../getting-started.md) incluye ambos archivos, los roles de seguridad y los comandos programados.

## Más información

- [Todas las funciones](../features.md) · [Primeros pasos](../getting-started.md) · [Conexión a la base de datos](../configuration.md)
- [Arquitectura y transiciones de estado](../architecture.md) · [Asuntos de tickets, acciones y traducciones](../customization.md)
- [Control de acceso](../access-control.md) · [Acceso a tickets y enrutamiento](../ticket-access-and-routing.md)
- [Integrar el widget](../embedding-the-widget.md) · [Acceso de invitados](../guest-access.md)
- [Panel del agente](../agent-panel.md) · [Integrar la administración](../embedding-admin.md)
- [Plugins](../plugins.md) · [Boletines](../newsletters.md)

Escalated también está disponible para [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring), [WordPress](https://github.com/escalated-dev/escalated-wordpress) y [Filament](https://github.com/escalated-dev/escalated-filament). La interfaz está en [escalated](https://github.com/escalated-dev/escalated).

## Licencia

MIT. Copyright (c) Escalated.dev. Consulta [LICENSE](../../LICENSE).
