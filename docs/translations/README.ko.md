<p align="center">
  <a href="README.ar.md">العربية</a> •
  <a href="README.de.md">Deutsch</a> •
  <a href="../../README.md">English</a> •
  <a href="README.es.md">Español</a> •
  <a href="README.fr.md">Français</a> •
  <a href="README.it.md">Italiano</a> •
  <a href="README.ja.md">日本語</a> •
  <b>한국어</b> •
  <a href="README.nl.md">Nederlands</a> •
  <a href="README.pl.md">Polski</a> •
  <a href="README.pt-BR.md">Português (BR)</a> •
  <a href="README.ru.md">Русский</a> •
  <a href="README.tr.md">Türkçe</a> •
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for Symfony

Symfony 애플리케이션용 임베디드 지원 티켓 시스템. 티켓, 답변, 부서, 태그, SLA 정책, 역할 기반 접근 제어를 갖춘 플러그앤플레이 헬프데스크.

## 요구 사항

- PHP 8.2+
- Symfony 6.4 또는 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## 설치

```bash
composer require escalated-dev/escalated-symfony
```

### 1. 번들 등록

Symfony Flex가 설치되어 있으면 번들이 자동으로 등록됩니다. 그렇지 않으면 `config/bundles.php`에 추가하세요:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. 번들 설정

`config/packages/escalated.yaml`을 생성하세요:

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

### 3. 마이그레이션 실행

```bash
php bin/console doctrine:migrations:migrate
```

마이그레이션은 필요한 모든 테이블을 생성합니다 (`escalated_tickets`, `escalated_replies`, `escalated_departments`, `escalated_tags`, `escalated_sla_policies`, `escalated_ticket_activities`, `escalated_agent_profiles`).

### 4. 보안 설정

사용자 제공자에서 관리자 사용자에게 `ROLE_ESCALATED_ADMIN` 역할을 추가하세요. 에이전트 접근은 사용자에 연결된 `AgentProfile` 엔티티의 존재 여부로 결정됩니다.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (선택 사항) Inertia 설치

내장 프론트엔드 UI를 위해 Inertia 번들을 설치하세요:

```bash
composer require rompetomp/inertia-bundle
# 또는
composer require skipthedragon/inertia-bundle
```

커스텀 프론트엔드로 API와 서비스만 사용하려면 `ui_enabled: false`로 설정하세요.

## 기능

- **티켓 라이프사이클** — 설정 가능한 상태 전환으로 생성, 할당, 답변, 해결, 닫기, 재오픈
- **SLA 엔진** — 우선순위별 응답 및 해결 목표, 영업시간 계산, 자동 위반 감지
- **에이전트 대시보드** — 필터, 내부 노트, 정형 응답이 있는 티켓 큐
- **고객 포털** — 셀프 서비스 티켓 생성, 답변 및 상태 추적
- **관리 패널** — 부서, SLA 정책, 태그 관리 및 보고서 조회
- **파일 첨부** — 설정 가능한 저장소 및 크기 제한의 드래그 앤 드롭 업로드
- **활동 타임라인** — 모든 티켓의 모든 작업에 대한 완전한 감사 로그
- **이메일 알림** — 이벤트별 설정 가능한 알림
- **부서 라우팅** — 자동 할당으로 에이전트를 부서에 조직
- **태그 시스템** — 색상 태그로 티켓 분류
- **티켓 분할** — 원본 컨텍스트를 보존하면서 답변을 새로운 독립 티켓으로 분할
- **티켓 스누즈** — 프리셋(1시간, 4시간, 내일, 다음 주)으로 티켓 스누즈; `php bin/console escalated:wake-snoozed-tickets` 콘솔 명령이 일정에 따라 자동으로 깨움
- **저장된 뷰 / 커스텀 큐** — 필터 프리셋을 재사용 가능한 티켓 뷰로 저장, 이름 지정 및 공유
- **임베디드 지원 위젯** — KB 검색, 티켓 양식, 상태 확인이 있는 경량 `<script>` 위젯
- **이메일 스레딩** — 발신 이메일에 메일 클라이언트에서의 올바른 스레딩을 위한 적절한 `In-Reply-To` 및 `References` 헤더 포함
- **브랜드 이메일 템플릿** — 모든 발신 이메일에 대해 설정 가능한 로고, 기본 색상, 바닥글 텍스트
- **실시간 브로드캐스팅** — Mercure를 통한 옵트인 브로드캐스팅, 자동 폴링 폴백 지원
- **지식 베이스 토글** — 관리 설정에서 공개 지식 베이스 활성화 또는 비활성화

## 아키텍처

### 엔티티

| 엔티티 | 설명 |
|---|---|
| `Ticket` | 상태, 우선순위, SLA 추적이 있는 지원 티켓 |
| `Reply` | 티켓에 대한 공개 답변 또는 내부 노트 |
| `Department` | 티켓과 에이전트를 위한 조직적 그룹 |
| `Tag` | 티켓 분류를 위한 레이블 |
| `SlaPolicy` | 우선순위별 첫 응답 및 해결 시간 목표 |
| `TicketActivity` | 모든 티켓 변경 사항의 감사 로그 |
| `AgentProfile` | 에이전트 메타데이터 (유형, 용량) |

### 서비스

| 서비스 | 설명 |
|---|---|
| `TicketService` | 티켓 생성, 업데이트, 전환, 답변 |
| `AssignmentService` | 에이전트 할당/해제, 워크로드 확인 |
| `SlaService` | SLA 정책 연결, 위반 확인 |

### 컨트롤러

라우트는 설정된 `route_prefix` 아래 네 그룹으로 구성됩니다:

- **Customer** (`/customer/tickets`) -- 인증된 최종 사용자를 위한 티켓 CRUD
- **Agent** (`/agent`) -- 지원 에이전트를 위한 대시보드 및 티켓 관리
- **Admin** (`/admin`) -- 티켓, 부서, 태그, 설정의 전체 관리
- **API** (`/api/v1`) -- 외부 통합을 위한 JSON REST API

### 보안

두 개의 Symfony voter가 접근을 제어합니다:

- `ESCALATED_AGENT` -- 사용자가 `AgentProfile` 레코드를 가지고 있을 때 부여
- `ESCALATED_ADMIN` -- 사용자가 `ROLE_ESCALATED_ADMIN` 역할을 가지고 있을 때 부여

### UI 렌더링

컨트롤러는 `UiRendererInterface`를 사용하여 페이지를 렌더링합니다. 기본 `InertiaUiRenderer`는 설치된 Inertia 번들에 위임합니다. Twig 또는 다른 렌더러를 사용하려면 `UiRendererInterface`를 구현하고 컨테이너 설정에서 서비스를 오버라이드하세요:

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## 상태 전환

티켓은 다음 전환을 가진 상태 머신을 따릅니다:

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

## 테스트

```bash
vendor/bin/phpunit
```

## 라이선스

MIT
