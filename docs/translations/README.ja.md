<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.it.md">Italiano</a> ·
  <b>日本語</b> ·
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
  Symfony アプリに本格的なヘルプデスクを追加できます。<br>
  チケット、SLA、エスカレーションルール、エージェント用の受信トレイ、カスタマーポータルが、あなた自身のデータベース上で動きます。
</p>

<p align="center">
  <a href="https://escalated.dev"><b>ウェブサイト</b></a> ·
  <a href="https://escalated.dev/docs"><b>ドキュメント</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>変更履歴</b></a>
</p>

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-dark.png">
  <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-light.png" alt="Escalated のエージェント用受信トレイ: 件数付きの保存済みビュー、SLA のカウントダウン、各チケットの優先度" width="100%">
</picture>

## できること

<table>
  <tr>
    <td width="40%">
      <h3>エージェントが一日中作業できる受信トレイ</h3>
      会話、内部メモ、定型返信、SLA タイマーを 1 つの画面にまとめています。チケットは Postmark、Mailgun、SES 経由のメールでも届きます。
    </td>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-light.png" alt="返信エディター付きでエージェントのワークスペースに開いたチケット">
      </picture>
    </td>
  </tr>
  <tr>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-light.png" alt="応答時間、CSAT、SLA 違反を表示する管理者用レポートページ">
      </picture>
    </td>
    <td width="40%">
      <h3>目に見える SLA とエスカレーションルール</h3>
      営業時間を考慮した優先度ごとの応答・解決目標、目標に遅れたときにエスカレーションや再割り当てを行うルール、違反のレポート。
    </td>
  </tr>
</table>

## 機能

<table>
<tr>
<td width="33%" valign="top">

**チケット**
- 作成、割り当て、返信、解決、再オープン
- 設定可能なステータス遷移
- 返信を新しいチケットに分割
- 自動再開付きのスヌーズ
- タグ、添付ファイル、アクティビティ履歴

</td>
<td width="33%" valign="top">

**エージェントワークスペース**
- フィルターと保存済みビュー付きのキュー
- 内部メモと定型返信
- 自動割り当て付きの部署
- Mercure によるリアルタイム更新

</td>
<td width="33%" valign="top">

**SLA とエスカレーション**
- 優先度ごとの応答・解決目標
- 営業時間
- 違反の自動検出
- エスカレーションルールと自動化

</td>
</tr>
<tr>
<td width="33%" valign="top">

**顧客**
- セルフサービスポータル: 作成、返信、追跡
- メールで届くコードによる確認済みゲストアクセス
- ナレッジベース検索付きの埋め込みウィジェット
- ゲスト向けエンドポイントのレート制限
- ナレッジベース (オン/オフ切り替え可)

</td>
<td width="33%" valign="top">

**メール**
- Postmark、Mailgun、SES 経由の受信
- イベントごとの通知
- メールクライアントでの正しいスレッド表示
- ブランドに合わせたテンプレート

</td>
<td width="33%" valign="top">

**管理とアクセス**
- 部署、SLA ポリシー、タグ、レポート
- ロール、権限、二要素認証、SSO
- 自分の管理パネル内に管理画面を配置
- 送信 Webhook とプラグイン

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">すべての機能の詳細</a></sub></p>

<p align="center">
  <a href="https://symfony.com"><img src="https://img.shields.io/badge/Symfony_6.4_or_7-000000?style=for-the-badge&logo=symfony&logoColor=white" alt="Symfony 6.4 or 7"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP_8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://www.doctrine-project.org"><img src="https://img.shields.io/badge/Doctrine-FC6A31?style=for-the-badge&logo=doctrine&logoColor=white" alt="Doctrine"></a>
  <a href="https://inertiajs.com"><img src="https://img.shields.io/badge/Inertia-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia"></a>
  <a href="https://vuejs.org"><img src="https://img.shields.io/badge/Vue_3-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue 3"></a>
</p>

## クイックスタート

```bash
composer require escalated-dev/escalated-symfony
composer require rompetomp/inertia-bundle
php bin/console doctrine:migrations:migrate
```

マイグレーションの前に `config/packages/escalated.yaml` を追加し、`config/routes/escalated.yaml` でバンドルのルートをインポートしてください。PHP 8.2+ と Symfony 6.4 または 7.x が必要です。[入門ガイド](../getting-started.md)に、両方のファイル、セキュリティロール、スケジュール実行するコマンドが載っています。

## 詳しく知る

- [すべての機能](../features.md) · [はじめに](../getting-started.md) · [データベース接続](../configuration.md)
- [アーキテクチャとステータス遷移](../architecture.md) · [チケットの件名、アクション、翻訳](../customization.md)
- [アクセス制御](../access-control.md) · [チケットのアクセスとルーティング](../ticket-access-and-routing.md)
- [ウィジェットの埋め込み](../embedding-the-widget.md) · [ゲストアクセス](../guest-access.md)
- [エージェントパネル](../agent-panel.md) · [管理画面の埋め込み](../embedding-admin.md)
- [プラグイン](../plugins.md) · [ニュースレター](../newsletters.md)

Escalated は [Laravel](https://github.com/escalated-dev/escalated-laravel)、[Rails](https://github.com/escalated-dev/escalated-rails)、[Django](https://github.com/escalated-dev/escalated-django)、[NestJS](https://github.com/escalated-dev/escalated-nestjs)、[AdonisJS](https://github.com/escalated-dev/escalated-adonis)、[Phoenix](https://github.com/escalated-dev/escalated-phoenix)、[Go](https://github.com/escalated-dev/escalated-go)、[.NET](https://github.com/escalated-dev/escalated-dotnet)、[Spring](https://github.com/escalated-dev/escalated-spring)、[WordPress](https://github.com/escalated-dev/escalated-wordpress)、[Filament](https://github.com/escalated-dev/escalated-filament) 向けにも提供しています。UI は [escalated](https://github.com/escalated-dev/escalated) にあります。

## ライセンス

MIT。Copyright (c) Escalated.dev。詳細は [LICENSE](../../LICENSE) を参照してください。
