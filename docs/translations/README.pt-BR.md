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
  <b>Português (BR)</b> ·
  <a href="README.ru.md">Русский</a> ·
  <a href="README.tr.md">Türkçe</a> ·
  <a href="README.zh-CN.md">简体中文</a>
</sub></p>

<p align="center">
  <a href="https://escalated.dev"><img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/logo.png" width="72" height="72" alt="Escalated"></a>
</p>

<h1 align="center">Escalated for Symfony</h1>

<p align="center">
  Adicione um helpdesk completo ao seu app Symfony.<br>
  Tickets, SLAs, regras de escalonamento, uma caixa de entrada para agentes e um portal do cliente, rodando no seu próprio banco de dados.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>Site</b></a> ·
  <a href="https://escalated.dev/docs"><b>Documentação</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>Histórico de mudanças</b></a>
</p>

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-dark.png">
  <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-light.png" alt="A caixa de entrada dos agentes no Escalated: visualizações salvas com contagens, contagens regressivas de SLA e prioridades em cada ticket" width="100%">
</picture>

## O que ele faz

<table>
  <tr>
    <td width="40%">
      <h3>Uma caixa de entrada em que seus agentes podem trabalhar o dia todo</h3>
      Conversa, notas internas, respostas prontas e cronômetros de SLA em uma só tela. Os tickets também chegam por e-mail via Postmark, Mailgun ou SES.
    </td>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-light.png" alt="Um ticket aberto no espaço de trabalho do agente com o editor de resposta">
      </picture>
    </td>
  </tr>
  <tr>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-light.png" alt="A página de relatórios do admin com tempos de resposta, CSAT e violações de SLA">
      </picture>
    </td>
    <td width="40%">
      <h3>SLAs e regras de escalonamento que você consegue ver</h3>
      Metas de resposta e resolução por prioridade com horário comercial, regras que escalonam ou reatribuem quando uma meta atrasa, e relatórios de violações.
    </td>
  </tr>
</table>

## Recursos

<table>
<tr>
<td width="33%" valign="top">

**Tickets**
- Criar, atribuir, responder, resolver, reabrir
- Transições de status configuráveis
- Separar uma resposta em um novo ticket
- Adiar com reativação automática
- Tags, anexos, linha do tempo de atividades

</td>
<td width="33%" valign="top">

**Espaço de trabalho do agente**
- Fila com filtros e visualizações salvas
- Notas internas e respostas prontas
- Departamentos com atribuição automática
- Atualizações em tempo real via Mercure

</td>
<td width="33%" valign="top">

**SLAs e escalonamento**
- Metas de resposta e resolução por prioridade
- Horário comercial
- Detecção automática de violações
- Regras de escalonamento e automações

</td>
</tr>
<tr>
<td width="33%" valign="top">

**Clientes**
- Portal de autoatendimento: abrir, responder, acompanhar
- Acesso de convidado verificado por código enviado por e-mail
- Widget incorporável com busca na base de conhecimento
- Limites de requisições nos endpoints de convidados
- Base de conhecimento, ativada ou desativada

</td>
<td width="33%" valign="top">

**E-mail**
- Entrada via Postmark, Mailgun, SES
- Notificações por evento
- Threads corretas nos clientes de e-mail
- Templates com a sua marca

</td>
<td width="33%" valign="top">

**Administração e acesso**
- Departamentos, políticas de SLA, tags, relatórios
- Papéis, permissões, autenticação de dois fatores, SSO
- Admin dentro do seu próprio painel de administração
- Webhooks de saída e plugins

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">Todos os recursos em detalhes</a></sub></p>

<p align="center">
  <a href="https://symfony.com"><img src="https://img.shields.io/badge/Symfony_6.4_or_7-000000?style=for-the-badge&logo=symfony&logoColor=white" alt="Symfony 6.4 or 7"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP_8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://www.doctrine-project.org"><img src="https://img.shields.io/badge/Doctrine-FC6A31?style=for-the-badge&logo=doctrine&logoColor=white" alt="Doctrine"></a>
  <a href="https://inertiajs.com"><img src="https://img.shields.io/badge/Inertia-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia"></a>
  <a href="https://vuejs.org"><img src="https://img.shields.io/badge/Vue_3-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue 3"></a>
</p>

## Início rápido

```bash
composer require escalated-dev/escalated-symfony
composer require rompetomp/inertia-bundle
php bin/console doctrine:migrations:migrate
```

Antes de migrar, adicione `config/packages/escalated.yaml` e importe as rotas do bundle em `config/routes/escalated.yaml`. Requer PHP 8.2+ e Symfony 6.4 ou 7.x. O [guia de primeiros passos](../getting-started.md) traz os dois arquivos, os papéis de segurança e os comandos agendados.

## Saiba mais

- [Todos os recursos](../features.md) · [Primeiros passos](../getting-started.md) · [Conexão com o banco de dados](../configuration.md)
- [Arquitetura e transições de status](../architecture.md) · [Assuntos de tickets, ações e traduções](../customization.md)
- [Controle de acesso](../access-control.md) · [Acesso a tickets e roteamento](../ticket-access-and-routing.md)
- [Incorporar o widget](../embedding-the-widget.md) · [Acesso de convidados](../guest-access.md)
- [Painel do agente](../agent-panel.md) · [Incorporar o admin](../embedding-admin.md)
- [Plugins](../plugins.md) · [Newsletters](../newsletters.md)

O Escalated também está disponível para [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring), [WordPress](https://github.com/escalated-dev/escalated-wordpress) e [Filament](https://github.com/escalated-dev/escalated-filament). A interface fica em [escalated](https://github.com/escalated-dev/escalated).

## Licença

MIT. Copyright (c) Escalated.dev. Veja [LICENSE](../../LICENSE).
