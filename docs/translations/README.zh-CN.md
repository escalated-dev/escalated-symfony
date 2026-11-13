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
  <a href="README.ru.md">Русский</a> ·
  <a href="README.tr.md">Türkçe</a> ·
  <b>简体中文</b>
</sub></p>

<p align="center">
  <a href="https://escalated.dev"><img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/logo.png" width="72" height="72" alt="Escalated"></a>
</p>

<h1 align="center">Escalated for Symfony</h1>

<p align="center">
  为你的 Symfony 应用添加一套完整的帮助台。<br>
  工单、SLA、升级规则、客服收件箱和客户门户，全部运行在你自己的数据库上。
</p>

<p align="center">
  <a href="https://escalated.dev"><b>官网</b></a> ·
  <a href="https://escalated.dev/docs"><b>文档</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>更新日志</b></a>
</p>

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-dark.png">
  <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-light.png" alt="Escalated 客服收件箱：带计数的已保存视图、SLA 倒计时以及每个工单的优先级" width="100%">
</picture>

## 它能做什么

<table>
  <tr>
    <td width="40%">
      <h3>客服可以整天在其中工作的收件箱</h3>
      对话、内部备注、预设回复和 SLA 计时器都在同一个界面上。工单也可以通过 Postmark、Mailgun 或 SES 以邮件形式进入。
    </td>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-light.png" alt="在客服工作区中打开的工单和回复编辑器">
      </picture>
    </td>
  </tr>
  <tr>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-light.png" alt="管理后台报表页面，显示响应时间、CSAT 和 SLA 违约情况">
      </picture>
    </td>
    <td width="40%">
      <h3>看得见的 SLA 和升级规则</h3>
      按优先级设置响应和解决目标并考虑工作时间，目标延误时升级或重新分配的规则，以及违约报表。
    </td>
  </tr>
</table>

## 功能特性

<table>
<tr>
<td width="33%" valign="top">

**工单**
- 创建、分配、回复、解决、重新打开
- 可配置的状态流转
- 将回复拆分为新工单
- 暂缓并自动唤醒
- 标签、附件、活动时间线

</td>
<td width="33%" valign="top">

**客服工作区**
- 带筛选和已保存视图的队列
- 内部备注和预设回复
- 支持自动分配的部门
- 通过 Mercure 实时更新

</td>
<td width="33%" valign="top">

**SLA 与升级**
- 按优先级设置响应和解决目标
- 工作时间
- 自动检测违约
- 升级规则和自动化

</td>
</tr>
<tr>
<td width="33%" valign="top">

**客户**
- 自助服务门户：提交、回复、跟踪
- 通过邮件验证码验证的访客访问
- 可嵌入的小组件，支持知识库搜索
- 访客端点的速率限制
- 知识库，可开可关

</td>
<td width="33%" valign="top">

**邮件**
- 通过 Postmark、Mailgun、SES 接收
- 按事件发送通知
- 在邮件客户端中正确归入会话
- 带品牌的模板

</td>
<td width="33%" valign="top">

**管理与权限**
- 部门、SLA 策略、标签、报表
- 角色、权限、双因素认证、SSO
- 在你自己的管理面板中使用管理功能
- 外发 Webhook 和插件

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">查看全部功能详情</a></sub></p>

<p align="center">
  <a href="https://symfony.com"><img src="https://img.shields.io/badge/Symfony_6.4_or_7-000000?style=for-the-badge&logo=symfony&logoColor=white" alt="Symfony 6.4 or 7"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP_8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://www.doctrine-project.org"><img src="https://img.shields.io/badge/Doctrine-FC6A31?style=for-the-badge&logo=doctrine&logoColor=white" alt="Doctrine"></a>
  <a href="https://inertiajs.com"><img src="https://img.shields.io/badge/Inertia-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia"></a>
  <a href="https://vuejs.org"><img src="https://img.shields.io/badge/Vue_3-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue 3"></a>
</p>

## 快速开始

```bash
composer require escalated-dev/escalated-symfony
composer require rompetomp/inertia-bundle
php bin/console doctrine:migrations:migrate
```

迁移之前，先添加 `config/packages/escalated.yaml`，并在 `config/routes/escalated.yaml` 中导入该 bundle 的路由。需要 PHP 8.2+ 和 Symfony 6.4 或 7.x。[入门指南](../getting-started.md)包含这两个文件、安全角色和计划任务命令。

## 了解更多

- [全部功能](../features.md) · [入门](../getting-started.md) · [数据库连接](../configuration.md)
- [架构和状态流转](../architecture.md) · [工单主题、操作和翻译](../customization.md)
- [访问控制](../access-control.md) · [工单访问和路由](../ticket-access-and-routing.md)
- [嵌入小组件](../embedding-the-widget.md) · [访客访问](../guest-access.md)
- [客服面板](../agent-panel.md) · [嵌入管理后台](../embedding-admin.md)
- [插件](../plugins.md) · [新闻通讯](../newsletters.md)

Escalated 也提供 [Laravel](https://github.com/escalated-dev/escalated-laravel)、[Rails](https://github.com/escalated-dev/escalated-rails)、[Django](https://github.com/escalated-dev/escalated-django)、[NestJS](https://github.com/escalated-dev/escalated-nestjs)、[AdonisJS](https://github.com/escalated-dev/escalated-adonis)、[Phoenix](https://github.com/escalated-dev/escalated-phoenix)、[Go](https://github.com/escalated-dev/escalated-go)、[.NET](https://github.com/escalated-dev/escalated-dotnet)、[Spring](https://github.com/escalated-dev/escalated-spring)、[WordPress](https://github.com/escalated-dev/escalated-wordpress) 和 [Filament](https://github.com/escalated-dev/escalated-filament) 版本。界面代码位于 [escalated](https://github.com/escalated-dev/escalated)。

## 许可证

MIT。Copyright (c) Escalated.dev。详见 [LICENSE](../../LICENSE)。
