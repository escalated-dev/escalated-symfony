<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <b>Español</b> •
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

Un sistema de tickets de soporte integrable para aplicaciones Symfony. Helpdesk plug-and-play con tickets, respuestas, departamentos, etiquetas, políticas SLA y control de acceso basado en roles.

## Requisitos

- PHP 8.2+
- Symfony 6.4 o 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## Instalación

```bash
composer require escalated-dev/escalated-symfony
```

### 1. Registrar el bundle

Si Symfony Flex está instalado, el bundle se registra automáticamente. De lo contrario, agrégalo a `config/bundles.php`:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. Configurar el bundle

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

### 3. Ejecutar migraciones

```bash
php bin/console doctrine:migrations:migrate
```

La migración crea todas las tablas necesarias (`escalated_tickets`, `escalated_replies`, `escalated_departments`, `escalated_tags`, `escalated_sla_policies`, `escalated_ticket_activities`, `escalated_agent_profiles`).

### 4. Configurar la seguridad

Agrega el rol `ROLE_ESCALATED_ADMIN` a los usuarios administradores en tu proveedor de usuarios. El acceso de agentes se determina por la presencia de una entidad `AgentProfile` vinculada al usuario.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (Opcional) Instalar Inertia

Para la interfaz de usuario frontend integrada, instala un bundle de Inertia:

```bash
composer require rompetomp/inertia-bundle
# o
composer require skipthedragon/inertia-bundle
```

Establece `ui_enabled: false` si quieres usar solo la API y los servicios con un frontend personalizado.

## Características

- **Ciclo de vida del ticket** — Crear, asignar, responder, resolver, cerrar, reabrir con transiciones de estado configurables
- **Motor SLA** — Objetivos de respuesta y resolución por prioridad, cálculo de horas laborales, detección automática de incumplimientos
- **Panel de agentes** — Cola de tickets con filtros, notas internas, respuestas predefinidas
- **Portal de clientes** — Creación de tickets de autoservicio, respuestas y seguimiento de estado
- **Panel de administración** — Gestionar departamentos, políticas SLA, etiquetas y ver reportes
- **Archivos adjuntos** — Carga por arrastrar y soltar con almacenamiento y límites de tamaño configurables
- **Línea de tiempo de actividad** — Registro de auditoría completo de cada acción en cada ticket
- **Notificaciones por correo** — Notificaciones configurables por evento
- **Enrutamiento por departamento** — Organizar agentes en departamentos con asignación automática
- **Sistema de etiquetas** — Categorizar tickets con etiquetas de colores
- **División de tickets** — Dividir una respuesta en un nuevo ticket independiente preservando el contexto original
- **Suspensión de tickets** — Suspender tickets con presets (1h, 4h, mañana, próxima semana); el comando `php bin/console escalated:wake-snoozed-tickets` los reactiva automáticamente según programación
- **Vistas guardadas / colas personalizadas** — Guardar, nombrar y compartir presets de filtros como vistas de tickets reutilizables
- **Widget de soporte integrable** — Widget ligero `<script>` con búsqueda en base de conocimiento, formulario de tickets y verificación de estado
- **Hilos de correo** — Los correos salientes incluyen cabeceras `In-Reply-To` y `References` apropiadas para un correcto hilo en los clientes de correo
- **Plantillas de correo con marca** — Logotipo, color primario y texto de pie de página configurables para todos los correos salientes
- **Transmisión en tiempo real** — Transmisión opcional via Mercure con respaldo de sondeo automático
- **Interruptor de base de conocimiento** — Habilitar o deshabilitar la base de conocimiento pública desde la configuración de administración

## Arquitectura

### Entidades

| Entidad | Descripción |
|---|---|
| `Ticket` | Ticket de soporte con estado, prioridad, seguimiento SLA |
| `Reply` | Respuesta pública o nota interna en un ticket |
| `Department` | Agrupación organizacional para tickets y agentes |
| `Tag` | Etiquetas para categorizar tickets |
| `SlaPolicy` | Objetivos de tiempo de primera respuesta y resolución por prioridad |
| `TicketActivity` | Registro de auditoría de todos los cambios en tickets |
| `AgentProfile` | Metadatos del agente (tipo, capacidad) |

### Servicios

| Servicio | Descripción |
|---|---|
| `TicketService` | Crear, actualizar, transicionar, responder tickets |
| `AssignmentService` | Asignar/desasignar agentes, verificar carga de trabajo |
| `SlaService` | Adjuntar políticas SLA, verificar incumplimientos |

### Controladores

Las rutas están organizadas en cuatro grupos, todos bajo el `route_prefix` configurado:

- **Customer** (`/customer/tickets`) -- CRUD de tickets para usuarios finales autenticados
- **Agent** (`/agent`) -- Panel de control y gestión de tickets para agentes de soporte
- **Admin** (`/admin`) -- Gestión completa de tickets, departamentos, etiquetas, configuración
- **API** (`/api/v1`) -- JSON REST API para integraciones externas

### Seguridad

Dos voters de Symfony controlan el acceso:

- `ESCALATED_AGENT` -- Otorgado cuando el usuario tiene un registro `AgentProfile`
- `ESCALATED_ADMIN` -- Otorgado cuando el usuario tiene el rol `ROLE_ESCALATED_ADMIN`

### Renderizado de UI

Los controladores usan `UiRendererInterface` para renderizar páginas. El `InertiaUiRenderer` predeterminado delega al bundle de Inertia que esté instalado. Para usar Twig u otro renderizador, implementa `UiRendererInterface` y sobreescribe el servicio en la configuración de tu contenedor:

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## Transiciones de Estado

Los tickets siguen una máquina de estados con estas transiciones:

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

## Pruebas

```bash
vendor/bin/phpunit
```

## Licencia

MIT
