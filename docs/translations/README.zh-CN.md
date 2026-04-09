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
  <a href="README.ru.md">Русский</a> •
  <a href="README.tr.md">Türkçe</a> •
  <b>简体中文</b>
</p>

# Escalated for Symfony

Symfony 应用程序的可嵌入支持工单系统。即插即用的帮助台，包含工单、回复、部门、标签、SLA 策略和基于角色的访问控制。

## 系统要求

- PHP 8.2+
- Symfony 6.4 或 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## 安装

```bash
composer require escalated-dev/escalated-symfony
```

### 1. 注册 Bundle

如果安装了 Symfony Flex，Bundle 会自动注册。否则，将其添加到 `config/bundles.php`：

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. 配置 Bundle

创建 `config/packages/escalated.yaml`：

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

### 3. 运行迁移

```bash
php bin/console doctrine:migrations:migrate
```

迁移会创建所有必要的表（`escalated_tickets`、`escalated_replies`、`escalated_departments`、`escalated_tags`、`escalated_sla_policies`、`escalated_ticket_activities`、`escalated_agent_profiles`）。

### 4. 设置安全性

在用户提供者中为管理员用户添加 `ROLE_ESCALATED_ADMIN` 角色。代理访问由与用户关联的 `AgentProfile` 实体的存在来确定。

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5.（可选）安装 Inertia

对于内置前端 UI，安装一个 Inertia Bundle：

```bash
composer require rompetomp/inertia-bundle
# 或
composer require skipthedragon/inertia-bundle
```

如果您只想使用 API 和服务配合自定义前端，请设置 `ui_enabled: false`。

## 功能特性

- **工单生命周期** — 创建、分配、回复、解决、关闭、重新打开，支持可配置的状态转换
- **SLA 引擎** — 按优先级的响应和解决目标、营业时间计算、自动违规检测
- **代理仪表板** — 带有过滤器、内部备注、预设回复的工单队列
- **客户门户** — 自助工单创建、回复和状态跟踪
- **管理面板** — 管理部门、SLA 策略、标签并查看报告
- **文件附件** — 可配置存储和大小限制的拖放上传
- **活动时间线** — 每个工单上每个操作的完整审计日志
- **邮件通知** — 按事件可配置的通知
- **部门路由** — 将代理组织到部门中并自动分配
- **标签系统** — 用彩色标签分类工单
- **工单拆分** — 将回复拆分为新的独立工单，同时保留原始上下文
- **工单延后** — 使用预设（1小时、4小时、明天、下周）延后工单；`php bin/console escalated:wake-snoozed-tickets` 控制台命令按计划自动唤醒
- **保存的视图 / 自定义队列** — 将过滤器预设保存、命名和共享为可重用的工单视图
- **可嵌入支持小部件** — 带有知识库搜索、工单表单和状态检查的轻量级 `<script>` 小部件
- **邮件线程** — 发送的邮件包含正确的 `In-Reply-To` 和 `References` 头部，以便在邮件客户端中正确显示线程
- **品牌邮件模板** — 所有发送邮件可配置的标志、主色调和页脚文本
- **实时广播** — 通过 Mercure 的可选广播，带有自动轮询回退
- **知识库开关** — 从管理设置中启用或禁用公共知识库

## 架构

### 实体

| 实体 | 描述 |
|---|---|
| `Ticket` | 带有状态、优先级、SLA 跟踪的支持工单 |
| `Reply` | 工单的公开回复或内部备注 |
| `Department` | 工单和代理的组织分组 |
| `Tag` | 用于分类工单的标签 |
| `SlaPolicy` | 按优先级的首次响应和解决时间目标 |
| `TicketActivity` | 所有工单变更的审计日志 |
| `AgentProfile` | 代理元数据（类型、容量） |

### 服务

| 服务 | 描述 |
|---|---|
| `TicketService` | 创建、更新、转换、回复工单 |
| `AssignmentService` | 分配/取消分配代理、检查工作负载 |
| `SlaService` | 附加 SLA 策略、检查违规 |

### 控制器

路由组织为四个组，全部在配置的 `route_prefix` 下：

- **Customer**（`/customer/tickets`）-- 已认证终端用户的工单 CRUD
- **Agent**（`/agent`）-- 支持代理的仪表板和工单管理
- **Admin**（`/admin`）-- 工单、部门、标签、设置的完整管理
- **API**（`/api/v1`）-- 用于外部集成的 JSON REST API

### 安全性

两个 Symfony voter 控制访问：

- `ESCALATED_AGENT` -- 当用户拥有 `AgentProfile` 记录时授予
- `ESCALATED_ADMIN` -- 当用户拥有 `ROLE_ESCALATED_ADMIN` 角色时授予

### UI 渲染

控制器使用 `UiRendererInterface` 来渲染页面。默认的 `InertiaUiRenderer` 委托给已安装的 Inertia Bundle。要使用 Twig 或其他渲染器，请实现 `UiRendererInterface` 并在容器配置中覆盖该服务：

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## 状态转换

工单遵循以下转换的状态机：

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

## 测试

```bash
vendor/bin/phpunit
```

## 许可证

MIT
