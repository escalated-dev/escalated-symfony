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
  <b>Português (BR)</b> •
  <a href="README.ru.md">Русский</a> •
  <a href="README.tr.md">Türkçe</a> •
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for Symfony

Um sistema de tickets de suporte embutível para aplicações Symfony. Helpdesk pronto para uso com tickets, respostas, departamentos, tags, políticas SLA e controle de acesso baseado em papéis.

## Requisitos

- PHP 8.2+
- Symfony 6.4 ou 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## Instalação

```bash
composer require escalated-dev/escalated-symfony
```

### 1. Registrar o bundle

Se o Symfony Flex estiver instalado, o bundle é registrado automaticamente. Caso contrário, adicione-o ao `config/bundles.php`:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. Configurar o bundle

Crie `config/packages/escalated.yaml`:

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

### 3. Executar migrações

```bash
php bin/console doctrine:migrations:migrate
```

A migração cria todas as tabelas necessárias (`escalated_tickets`, `escalated_replies`, `escalated_departments`, `escalated_tags`, `escalated_sla_policies`, `escalated_ticket_activities`, `escalated_agent_profiles`).

### 4. Configurar segurança

Adicione o papel `ROLE_ESCALATED_ADMIN` aos usuários administradores no seu provedor de usuários. O acesso de agentes é determinado pela presença de uma entidade `AgentProfile` vinculada ao usuário.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (Opcional) Instalar Inertia

Para a interface frontend embutida, instale um bundle Inertia:

```bash
composer require rompetomp/inertia-bundle
# ou
composer require skipthedragon/inertia-bundle
```

Defina `ui_enabled: false` se quiser usar apenas a API e os serviços com um frontend personalizado.

## Recursos

- **Ciclo de vida do ticket** — Criar, atribuir, responder, resolver, fechar, reabrir com transições de status configuráveis
- **Motor SLA** — Metas de resposta e resolução por prioridade, cálculo de horário comercial, detecção automática de violações
- **Painel de agentes** — Fila de tickets com filtros, notas internas, respostas prontas
- **Portal do cliente** — Criação self-service de tickets, respostas e rastreamento de status
- **Painel administrativo** — Gerenciar departamentos, políticas SLA, tags e visualizar relatórios
- **Anexos de arquivos** — Upload por arrastar e soltar com armazenamento e limites de tamanho configuráveis
- **Linha do tempo de atividades** — Log de auditoria completo de cada ação em cada ticket
- **Notificações por email** — Notificações configuráveis por evento
- **Roteamento por departamento** — Organizar agentes em departamentos com atribuição automática
- **Sistema de tags** — Categorizar tickets com tags coloridas
- **Divisão de tickets** — Dividir uma resposta em um novo ticket independente preservando o contexto original
- **Suspensão de tickets** — Suspender tickets com predefinições (1h, 4h, amanhã, próxima semana); o comando `php bin/console escalated:wake-snoozed-tickets` os reativa automaticamente conforme programação
- **Visualizações salvas / filas personalizadas** — Salvar, nomear e compartilhar predefinições de filtros como visualizações de tickets reutilizáveis
- **Widget de suporte embutível** — Widget leve `<script>` com pesquisa na base de conhecimento, formulário de ticket e verificação de status
- **Threading de email** — Emails enviados incluem cabeçalhos `In-Reply-To` e `References` adequados para threading correto em clientes de email
- **Templates de email com marca** — Logo, cor primária e texto de rodapé configuráveis para todos os emails enviados
- **Transmissão em tempo real** — Transmissão opt-in via Mercure com fallback automático para polling
- **Interruptor da base de conhecimento** — Habilitar ou desabilitar a base de conhecimento pública nas configurações de administração

## Arquitetura

### Entidades

| Entidade | Descrição |
|---|---|
| `Ticket` | Ticket de suporte com status, prioridade, rastreamento SLA |
| `Reply` | Resposta pública ou nota interna em um ticket |
| `Department` | Agrupamento organizacional para tickets e agentes |
| `Tag` | Rótulos para categorizar tickets |
| `SlaPolicy` | Metas de tempo de primeira resposta e resolução por prioridade |
| `TicketActivity` | Log de auditoria de todas as alterações de tickets |
| `AgentProfile` | Metadados do agente (tipo, capacidade) |

### Serviços

| Serviço | Descrição |
|---|---|
| `TicketService` | Criar, atualizar, transicionar, responder tickets |
| `AssignmentService` | Atribuir/desatribuir agentes, verificar carga de trabalho |
| `SlaService` | Anexar políticas SLA, verificar violações |

### Controladores

As rotas são organizadas em quatro grupos, todos sob o `route_prefix` configurado:

- **Customer** (`/customer/tickets`) -- CRUD de tickets para usuários finais autenticados
- **Agent** (`/agent`) -- Painel e gerenciamento de tickets para agentes de suporte
- **Admin** (`/admin`) -- Gerenciamento completo de tickets, departamentos, tags, configurações
- **API** (`/api/v1`) -- JSON REST API para integrações externas

### Segurança

Dois voters Symfony controlam o acesso:

- `ESCALATED_AGENT` -- Concedido quando o usuário tem um registro `AgentProfile`
- `ESCALATED_ADMIN` -- Concedido quando o usuário tem o papel `ROLE_ESCALATED_ADMIN`

### Renderização de UI

Os controladores usam `UiRendererInterface` para renderizar páginas. O `InertiaUiRenderer` padrão delega ao bundle Inertia instalado. Para usar Twig ou outro renderizador, implemente `UiRendererInterface` e sobrescreva o serviço na configuração do seu container:

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## Transições de Status

Os tickets seguem uma máquina de estados com estas transições:

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

## Testes

```bash
vendor/bin/phpunit
```

## Licença

MIT
