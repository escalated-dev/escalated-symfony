<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <a href="README.it.md">Italiano</a> •
  <b>日本語</b> •
  <a href="README.ko.md">한국어</a> •
  <a href="README.nl.md">Nederlands</a> •
  <a href="README.pl.md">Polski</a> •
  <a href="README.pt-BR.md">Português (BR)</a> •
  <a href="README.ru.md">Русский</a> •
  <a href="README.tr.md">Türkçe</a> •
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for Symfony

Symfonyアプリケーション向けの組み込み可能なサポートチケットシステム。チケット、返信、部門、タグ、SLAポリシー、ロールベースのアクセス制御を備えたプラグアンドプレイのヘルプデスク。

## 要件

- PHP 8.2+
- Symfony 6.4 または 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## インストール

```bash
composer require escalated-dev/escalated-symfony
```

### 1. バンドルの登録

Symfony Flexがインストールされている場合、バンドルは自動的に登録されます。そうでない場合は、`config/bundles.php`に追加してください:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. バンドルの設定

`config/packages/escalated.yaml`を作成します:

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

### 3. マイグレーションの実行

```bash
php bin/console doctrine:migrations:migrate
```

マイグレーションにより、必要なテーブルがすべて作成されます（`escalated_tickets`、`escalated_replies`、`escalated_departments`、`escalated_tags`、`escalated_sla_policies`、`escalated_ticket_activities`、`escalated_agent_profiles`）。

### 4. セキュリティの設定

ユーザープロバイダーの管理者ユーザーに`ROLE_ESCALATED_ADMIN`ロールを追加します。エージェントアクセスは、ユーザーにリンクされた`AgentProfile`エンティティの存在によって決定されます。

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. （オプション）Inertiaのインストール

組み込みフロントエンドUIには、Inertiaバンドルをインストールします:

```bash
composer require rompetomp/inertia-bundle
# または
composer require skipthedragon/inertia-bundle
```

カスタムフロントエンドでAPIとサービスのみを使用する場合は、`ui_enabled: false`に設定してください。

## 機能

- **チケットライフサイクル** — 設定可能なステータス遷移で作成、割り当て、返信、解決、クローズ、再オープン
- **SLAエンジン** — 優先度ごとの応答・解決目標、営業時間計算、自動違反検出
- **エージェントダッシュボード** — フィルター、内部メモ、定型応答を備えたチケットキュー
- **カスタマーポータル** — セルフサービスのチケット作成、返信、ステータス追跡
- **管理パネル** — 部門、SLAポリシー、タグの管理とレポート表示
- **ファイル添付** — 設定可能なストレージとサイズ制限付きのドラッグ＆ドロップアップロード
- **アクティビティタイムライン** — すべてのチケットのすべてのアクションの完全な監査ログ
- **メール通知** — イベントごとの設定可能な通知
- **部門ルーティング** — 自動割り当てでエージェントを部門に組織化
- **タグシステム** — 色付きタグでチケットを分類
- **チケット分割** — 元のコンテキストを保持しながら、返信を新しい独立したチケットに分割
- **チケットスヌーズ** — プリセット（1時間、4時間、明日、来週）でチケットをスヌーズ；`php bin/console escalated:wake-snoozed-tickets`コンソールコマンドがスケジュールに従って自動的にウェイクアップ
- **保存されたビュー / カスタムキュー** — フィルタープリセットを再利用可能なチケットビューとして保存、名前付け、共有
- **埋め込み可能なサポートウィジェット** — KB検索、チケットフォーム、ステータス確認を備えた軽量な`<script>`ウィジェット
- **メールスレッディング** — 送信メールにはメールクライアントでの正しいスレッディングのために適切な`In-Reply-To`および`References`ヘッダーが含まれます
- **ブランドメールテンプレート** — すべての送信メールに設定可能なロゴ、プライマリカラー、フッターテキスト
- **リアルタイムブロードキャスト** — Mercure経由のオプトインブロードキャスト、自動ポーリングフォールバック付き
- **ナレッジベーストグル** — 管理設定から公開ナレッジベースの有効化・無効化

## アーキテクチャ

### エンティティ

| エンティティ | 説明 |
|---|---|
| `Ticket` | ステータス、優先度、SLAトラッキング付きのサポートチケット |
| `Reply` | チケットへの公開返信または内部メモ |
| `Department` | チケットとエージェントの組織的グループ |
| `Tag` | チケットを分類するためのラベル |
| `SlaPolicy` | 優先度ごとの初回応答・解決時間目標 |
| `TicketActivity` | すべてのチケット変更の監査ログ |
| `AgentProfile` | エージェントのメタデータ（タイプ、キャパシティ） |

### サービス

| サービス | 説明 |
|---|---|
| `TicketService` | チケットの作成、更新、遷移、返信 |
| `AssignmentService` | エージェントの割り当て・解除、ワークロード確認 |
| `SlaService` | SLAポリシーの添付、違反チェック |

### コントローラー

ルートは設定された`route_prefix`の下に4つのグループで構成されています:

- **Customer** (`/customer/tickets`) -- 認証済みエンドユーザー向けチケットCRUD
- **Agent** (`/agent`) -- サポートエージェント向けダッシュボードとチケット管理
- **Admin** (`/admin`) -- チケット、部門、タグ、設定の完全な管理
- **API** (`/api/v1`) -- 外部統合用JSON REST API

### セキュリティ

2つのSymfony voterがアクセスを制御します:

- `ESCALATED_AGENT` -- ユーザーが`AgentProfile`レコードを持つ場合に付与
- `ESCALATED_ADMIN` -- ユーザーが`ROLE_ESCALATED_ADMIN`ロールを持つ場合に付与

### UIレンダリング

コントローラーは`UiRendererInterface`を使用してページをレンダリングします。デフォルトの`InertiaUiRenderer`はインストールされているInertiaバンドルに委譲します。Twigや他のレンダラーを使用するには、`UiRendererInterface`を実装し、コンテナ設定でサービスをオーバーライドしてください:

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## ステータス遷移

チケットは以下の遷移を持つステートマシンに従います:

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

## テスト

```bash
vendor/bin/phpunit
```

## ライセンス

MIT
