<p align="center">
  <b>العربية</b> •
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
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for Symfony

نظام تذاكر دعم قابل للتضمين لتطبيقات Symfony. مكتب مساعدة جاهز مع تذاكر وردود وأقسام ووسوم وسياسات SLA وتحكم في الوصول على أساس الأدوار.

## المتطلبات

- PHP 8.2+
- Symfony 6.4 أو 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## التثبيت

```bash
composer require escalated-dev/escalated-symfony
```

### 1. تسجيل الحزمة

إذا كان Symfony Flex مثبتاً، يتم تسجيل الحزمة تلقائياً. وإلا، أضفها إلى `config/bundles.php`:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. تكوين الحزمة

أنشئ `config/packages/escalated.yaml`:

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

### 3. تشغيل الترحيلات

```bash
php bin/console doctrine:migrations:migrate
```

يقوم الترحيل بإنشاء جميع الجداول الضرورية (`escalated_tickets`، `escalated_replies`، `escalated_departments`، `escalated_tags`، `escalated_sla_policies`، `escalated_ticket_activities`، `escalated_agent_profiles`).

### 4. إعداد الأمان

أضف دور `ROLE_ESCALATED_ADMIN` لمستخدمي المسؤول في مزود المستخدمين الخاص بك. يتم تحديد وصول الوكيل من خلال وجود كيان `AgentProfile` مرتبط بالمستخدم.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (اختياري) تثبيت Inertia

للواجهة الأمامية المدمجة، قم بتثبيت حزمة Inertia:

```bash
composer require rompetomp/inertia-bundle
# أو
composer require skipthedragon/inertia-bundle
```

اضبط `ui_enabled: false` إذا كنت تريد استخدام API والخدمات فقط مع واجهة أمامية مخصصة.

## الميزات

- **دورة حياة التذكرة** — إنشاء، تعيين، رد، حل، إغلاق، إعادة فتح مع انتقالات حالة قابلة للتكوين
- **محرك SLA** — أهداف الاستجابة والحل لكل أولوية، حساب ساعات العمل، كشف الانتهاكات التلقائي
- **لوحة تحكم الوكلاء** — قائمة تذاكر مع فلاتر، ملاحظات داخلية، ردود جاهزة
- **بوابة العملاء** — إنشاء تذاكر ذاتية الخدمة، ردود، وتتبع الحالة
- **لوحة الإدارة** — إدارة الأقسام، سياسات SLA، الوسوم وعرض التقارير
- **مرفقات الملفات** — رفع بالسحب والإفلات مع تخزين وحدود حجم قابلة للتكوين
- **الجدول الزمني للنشاط** — سجل تدقيق كامل لكل إجراء على كل تذكرة
- **إشعارات البريد الإلكتروني** — إشعارات قابلة للتكوين لكل حدث
- **توجيه الأقسام** — تنظيم الوكلاء في أقسام مع التعيين التلقائي
- **نظام الوسوم** — تصنيف التذاكر بوسوم ملونة
- **تقسيم التذاكر** — تقسيم رد إلى تذكرة مستقلة جديدة مع الحفاظ على السياق الأصلي
- **تأجيل التذاكر** — تأجيل التذاكر مع إعدادات مسبقة (ساعة، 4 ساعات، غداً، الأسبوع القادم)؛ أمر `php bin/console escalated:wake-snoozed-tickets` يوقظها تلقائياً حسب الجدول
- **العروض المحفوظة / قوائم مخصصة** — حفظ وتسمية ومشاركة إعدادات الفلتر كعروض تذاكر قابلة لإعادة الاستخدام
- **أداة دعم قابلة للتضمين** — أداة `<script>` خفيفة مع بحث في قاعدة المعرفة ونموذج تذكرة وفحص الحالة
- **ربط سلاسل البريد** — رسائل البريد الصادرة تتضمن رؤوس `In-Reply-To` و `References` الصحيحة لترابط صحيح في عملاء البريد
- **قوالب بريد ذات علامة تجارية** — شعار ولون أساسي ونص تذييل قابل للتكوين لجميع رسائل البريد الصادرة
- **البث في الوقت الفعلي** — بث اختياري عبر Mercure مع رجوع تلقائي إلى الاستطلاع
- **مفتاح قاعدة المعرفة** — تمكين أو تعطيل قاعدة المعرفة العامة من إعدادات الإدارة

## الهندسة المعمارية

### الكيانات

| الكيان | الوصف |
|---|---|
| `Ticket` | تذكرة دعم مع الحالة والأولوية وتتبع SLA |
| `Reply` | رد عام أو ملاحظة داخلية على تذكرة |
| `Department` | تجميع تنظيمي للتذاكر والوكلاء |
| `Tag` | تسميات لتصنيف التذاكر |
| `SlaPolicy` | أهداف وقت الاستجابة الأولى والحل لكل أولوية |
| `TicketActivity` | سجل تدقيق لجميع تغييرات التذاكر |
| `AgentProfile` | بيانات الوكيل الوصفية (النوع، السعة) |

### الخدمات

| الخدمة | الوصف |
|---|---|
| `TicketService` | إنشاء، تحديث، انتقال، رد على التذاكر |
| `AssignmentService` | تعيين/إلغاء تعيين الوكلاء، فحص عبء العمل |
| `SlaService` | إرفاق سياسات SLA، فحص الانتهاكات |

### وحدات التحكم

يتم تنظيم المسارات في أربع مجموعات، جميعها تحت `route_prefix` المُعدّ:

- **Customer** (`/customer/tickets`) -- عمليات CRUD للتذاكر للمستخدمين المصادق عليهم
- **Agent** (`/agent`) -- لوحة التحكم وإدارة التذاكر لوكلاء الدعم
- **Admin** (`/admin`) -- إدارة كاملة للتذاكر والأقسام والوسوم والإعدادات
- **API** (`/api/v1`) -- JSON REST API للتكاملات الخارجية

### الأمان

يتحكم مصوتان في Symfony في الوصول:

- `ESCALATED_AGENT` -- يُمنح عندما يكون لدى المستخدم سجل `AgentProfile`
- `ESCALATED_ADMIN` -- يُمنح عندما يكون لدى المستخدم دور `ROLE_ESCALATED_ADMIN`

### عرض واجهة المستخدم

تستخدم وحدات التحكم `UiRendererInterface` لعرض الصفحات. يفوض `InertiaUiRenderer` الافتراضي إلى حزمة Inertia المثبتة. لاستخدام Twig أو عارض آخر، قم بتنفيذ `UiRendererInterface` وتجاوز الخدمة في تكوين الحاوية:

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## انتقالات الحالة

تتبع التذاكر آلة حالة مع هذه الانتقالات:

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

## الاختبار

```bash
vendor/bin/phpunit
```

## الرخصة

MIT
