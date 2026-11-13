<p align="center"><sub>
  <a href="README.ar.md">العربية</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="../../README.md">English</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.it.md">Italiano</a> ·
  <a href="README.ja.md">日本語</a> ·
  <b>한국어</b> ·
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
  Symfony 앱에 완전한 헬프데스크를 추가하세요.<br>
  티켓, SLA, 에스컬레이션 규칙, 상담원 받은편지함, 고객 포털이 여러분의 데이터베이스에서 동작합니다.
</p>

<p align="center">
  <a href="https://escalated.dev"><b>웹사이트</b></a> ·
  <a href="https://escalated.dev/docs"><b>문서</b></a> ·
  <a href="https://escalated.dev/cloud"><b>Cloud</b></a> ·
  <a href="../../CHANGELOG.md"><b>변경 내역</b></a>
</p>

<br>

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-dark.png">
  <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/inbox-light.png" alt="Escalated 상담원 받은편지함: 개수가 표시된 저장된 보기, SLA 카운트다운, 모든 티켓의 우선순위" width="100%">
</picture>

## 하는 일

<table>
  <tr>
    <td width="40%">
      <h3>상담원이 하루 종일 작업할 수 있는 받은편지함</h3>
      대화, 내부 메모, 미리 준비된 답변, SLA 타이머를 한 화면에서 다룹니다. 티켓은 Postmark, Mailgun, SES를 통해 이메일로도 들어옵니다.
    </td>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/ticket-light.png" alt="답장 작성기와 함께 상담원 작업 공간에 열린 티켓">
      </picture>
    </td>
  </tr>
  <tr>
    <td width="60%">
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-dark.png">
        <img src="https://raw.githubusercontent.com/escalated-dev/escalated/main/docs/assets/readme/dashboard-light.png" alt="응답 시간, CSAT, SLA 위반을 보여 주는 관리자 보고서 페이지">
      </picture>
    </td>
    <td width="40%">
      <h3>한눈에 보이는 SLA와 에스컬레이션 규칙</h3>
      업무 시간을 반영한 우선순위별 응답 및 해결 목표, 목표를 놓치면 에스컬레이션하거나 재배정하는 규칙, 위반 보고서.
    </td>
  </tr>
</table>

## 기능

<table>
<tr>
<td width="33%" valign="top">

**티켓**
- 생성, 배정, 답장, 해결, 다시 열기
- 설정 가능한 상태 전환
- 답장을 새 티켓으로 분리
- 자동 재개되는 스누즈
- 태그, 첨부 파일, 활동 타임라인

</td>
<td width="33%" valign="top">

**상담원 작업 공간**
- 필터와 저장된 보기가 있는 대기열
- 내부 메모와 미리 준비된 답변
- 자동 배정을 지원하는 부서
- Mercure를 통한 실시간 업데이트

</td>
<td width="33%" valign="top">

**SLA와 에스컬레이션**
- 우선순위별 응답 및 해결 목표
- 업무 시간
- 위반 자동 감지
- 에스컬레이션 규칙과 자동화

</td>
</tr>
<tr>
<td width="33%" valign="top">

**고객**
- 셀프서비스 포털: 생성, 답장, 추적
- 이메일 코드로 확인된 게스트 접근
- 지식 베이스 검색이 있는 임베드 위젯
- 게스트 엔드포인트 요청 제한
- 지식 베이스, 켜거나 끄기

</td>
<td width="33%" valign="top">

**이메일**
- Postmark, Mailgun, SES를 통한 수신
- 이벤트별 알림
- 메일 클라이언트에서 올바른 스레드 표시
- 브랜드에 맞춘 템플릿

</td>
<td width="33%" valign="top">

**관리와 접근**
- 부서, SLA 정책, 태그, 보고서
- 역할, 권한, 2단계 인증, SSO
- 여러분의 관리자 패널 안에서 쓰는 관리 화면
- 발신 웹훅과 플러그인

</td>
</tr>
</table>

<p align="center"><sub><a href="../features.md">모든 기능 자세히 보기</a></sub></p>

<p align="center">
  <a href="https://symfony.com"><img src="https://img.shields.io/badge/Symfony_6.4_or_7-000000?style=for-the-badge&logo=symfony&logoColor=white" alt="Symfony 6.4 or 7"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP_8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://www.doctrine-project.org"><img src="https://img.shields.io/badge/Doctrine-FC6A31?style=for-the-badge&logo=doctrine&logoColor=white" alt="Doctrine"></a>
  <a href="https://inertiajs.com"><img src="https://img.shields.io/badge/Inertia-9553E9?style=for-the-badge&logo=inertia&logoColor=white" alt="Inertia"></a>
  <a href="https://vuejs.org"><img src="https://img.shields.io/badge/Vue_3-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue 3"></a>
</p>

## 빠른 시작

```bash
composer require escalated-dev/escalated-symfony
composer require rompetomp/inertia-bundle
php bin/console doctrine:migrations:migrate
```

마이그레이션하기 전에 `config/packages/escalated.yaml`을 추가하고 `config/routes/escalated.yaml`에서 번들의 라우트를 가져오세요. PHP 8.2+와 Symfony 6.4 또는 7.x가 필요합니다. [시작 가이드](../getting-started.md)에 두 파일, 보안 역할, 예약 명령이 모두 나와 있습니다.

## 더 알아보기

- [모든 기능](../features.md) · [시작하기](../getting-started.md) · [데이터베이스 연결](../configuration.md)
- [아키텍처와 상태 전환](../architecture.md) · [티켓 제목, 액션, 번역](../customization.md)
- [접근 제어](../access-control.md) · [티켓 접근과 라우팅](../ticket-access-and-routing.md)
- [위젯 임베드](../embedding-the-widget.md) · [게스트 접근](../guest-access.md)
- [상담원 패널](../agent-panel.md) · [관리자 화면 임베드](../embedding-admin.md)
- [플러그인](../plugins.md) · [뉴스레터](../newsletters.md)

Escalated는 [Laravel](https://github.com/escalated-dev/escalated-laravel), [Rails](https://github.com/escalated-dev/escalated-rails), [Django](https://github.com/escalated-dev/escalated-django), [NestJS](https://github.com/escalated-dev/escalated-nestjs), [AdonisJS](https://github.com/escalated-dev/escalated-adonis), [Phoenix](https://github.com/escalated-dev/escalated-phoenix), [Go](https://github.com/escalated-dev/escalated-go), [.NET](https://github.com/escalated-dev/escalated-dotnet), [Spring](https://github.com/escalated-dev/escalated-spring), [WordPress](https://github.com/escalated-dev/escalated-wordpress), [Filament](https://github.com/escalated-dev/escalated-filament)용으로도 제공됩니다. UI는 [escalated](https://github.com/escalated-dev/escalated) 저장소에 있습니다.

## 라이선스

MIT. Copyright (c) Escalated.dev. [LICENSE](../../LICENSE)를 참고하세요.
