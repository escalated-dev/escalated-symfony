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
  <b>Türkçe</b> •
  <a href="README.zh-CN.md">简体中文</a>
</p>

# Escalated for Symfony

Symfony uygulamaları için gömülebilir destek bilet sistemi. Biletler, yanıtlar, departmanlar, etiketler, SLA politikaları ve rol tabanlı erişim kontrolü ile hazır yardım masası.

## Gereksinimler

- PHP 8.2+
- Symfony 6.4 veya 7.x
- Doctrine ORM 2.17+ / 3.x
- Doctrine Migrations Bundle

## Kurulum

```bash
composer require escalated-dev/escalated-symfony
```

### 1. Bundle'ı kaydetme

Symfony Flex kuruluysa, bundle otomatik olarak kaydedilir. Aksi takdirde `config/bundles.php` dosyasına ekleyin:

```php
return [
    // ...
    Escalated\Symfony\EscalatedBundle::class => ['all' => true],
];
```

### 2. Bundle'ı yapılandırma

`config/packages/escalated.yaml` dosyasını oluşturun:

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

### 3. Migrasyonları çalıştırma

```bash
php bin/console doctrine:migrations:migrate
```

Migrasyon tüm gerekli tabloları oluşturur (`escalated_tickets`, `escalated_replies`, `escalated_departments`, `escalated_tags`, `escalated_sla_policies`, `escalated_ticket_activities`, `escalated_agent_profiles`).

### 4. Güvenliği ayarlama

Kullanıcı sağlayıcınızda yönetici kullanıcılara `ROLE_ESCALATED_ADMIN` rolünü ekleyin. Temsilci erişimi, kullanıcıya bağlı bir `AgentProfile` varlığının varlığıyla belirlenir.

```yaml
# config/packages/security.yaml
security:
    role_hierarchy:
        ROLE_ESCALATED_ADMIN: ROLE_USER
```

### 5. (İsteğe bağlı) Inertia kurulumu

Yerleşik frontend arayüzü için bir Inertia bundle'ı kurun:

```bash
composer require rompetomp/inertia-bundle
# veya
composer require skipthedragon/inertia-bundle
```

Yalnızca API ve hizmetleri özel bir frontend ile kullanmak istiyorsanız `ui_enabled: false` olarak ayarlayın.

## Özellikler

- **Bilet yaşam döngüsü** — Yapılandırılabilir durum geçişleriyle oluşturma, atama, yanıtlama, çözme, kapatma, yeniden açma
- **SLA motoru** — Önceliğe göre yanıt ve çözüm hedefleri, iş saatleri hesaplaması, otomatik ihlal tespiti
- **Temsilci paneli** — Filtreler, dahili notlar, hazır yanıtlar ile bilet kuyruğu
- **Müşteri portalı** — Self-servis bilet oluşturma, yanıtlar ve durum takibi
- **Yönetici paneli** — Departmanları, SLA politikalarını, etiketleri yönetme ve raporları görüntüleme
- **Dosya ekleri** — Yapılandırılabilir depolama ve boyut limitleri ile sürükle-bırak yükleme
- **Aktivite zaman çizelgesi** — Her bilet üzerindeki her eylemin tam denetim günlüğü
- **E-posta bildirimleri** — Olay bazında yapılandırılabilir bildirimler
- **Departman yönlendirmesi** — Otomatik atamayla temsilcileri departmanlara organize etme
- **Etiket sistemi** — Renkli etiketlerle biletleri kategorize etme
- **Bilet bölme** — Orijinal bağlamı koruyarak bir yanıtı yeni bağımsız bir bilete bölme
- **Bilet erteleme** — Ön ayarlarla (1s, 4s, yarın, gelecek hafta) biletleri erteleme; `php bin/console escalated:wake-snoozed-tickets` komutu programa göre otomatik uyandırır
- **Kayıtlı görünümler / özel kuyruklar** — Filtre ön ayarlarını yeniden kullanılabilir bilet görünümleri olarak kaydetme, adlandırma ve paylaşma
- **Gömülebilir destek widget'ı** — KB araması, bilet formu ve durum kontrolü olan hafif `<script>` widget'ı
- **E-posta threading** — Giden e-postalar, posta istemcilerinde doğru iş parçacığı oluşturma için uygun `In-Reply-To` ve `References` başlıkları içerir
- **Markalı e-posta şablonları** — Tüm giden e-postalar için yapılandırılabilir logo, ana renk ve alt bilgi metni
- **Gerçek zamanlı yayın** — Otomatik yoklama geri dönüşlü Mercure üzerinden isteğe bağlı yayın
- **Bilgi tabanı anahtarı** — Yönetici ayarlarından genel bilgi tabanını etkinleştirme veya devre dışı bırakma

## Mimari

### Varlıklar

| Varlık | Açıklama |
|---|---|
| `Ticket` | Durum, öncelik, SLA takibi olan destek bileti |
| `Reply` | Bilet üzerindeki genel yanıt veya dahili not |
| `Department` | Biletler ve temsilciler için organizasyonel gruplama |
| `Tag` | Biletleri kategorize etmek için etiketler |
| `SlaPolicy` | Önceliğe göre ilk yanıt ve çözüm süre hedefleri |
| `TicketActivity` | Tüm bilet değişikliklerinin denetim günlüğü |
| `AgentProfile` | Temsilci meta verileri (tür, kapasite) |

### Hizmetler

| Hizmet | Açıklama |
|---|---|
| `TicketService` | Bilet oluşturma, güncelleme, geçiş, yanıtlama |
| `AssignmentService` | Temsilci atama/kaldırma, iş yükü kontrolü |
| `SlaService` | SLA politikalarını ekleme, ihlalleri kontrol etme |

### Denetleyiciler

Rotalar, yapılandırılmış `route_prefix` altında dört gruba ayrılmıştır:

- **Customer** (`/customer/tickets`) -- Kimliği doğrulanmış son kullanıcılar için bilet CRUD
- **Agent** (`/agent`) -- Destek temsilcileri için panel ve bilet yönetimi
- **Admin** (`/admin`) -- Biletler, departmanlar, etiketler, ayarların tam yönetimi
- **API** (`/api/v1`) -- Dış entegrasyonlar için JSON REST API

### Güvenlik

İki Symfony voter erişimi kontrol eder:

- `ESCALATED_AGENT` -- Kullanıcının bir `AgentProfile` kaydı olduğunda verilir
- `ESCALATED_ADMIN` -- Kullanıcının `ROLE_ESCALATED_ADMIN` rolü olduğunda verilir

### UI Oluşturma

Denetleyiciler sayfaları oluşturmak için `UiRendererInterface` kullanır. Varsayılan `InertiaUiRenderer`, kurulu Inertia bundle'ına yetki verir. Twig veya başka bir oluşturucu kullanmak için `UiRendererInterface`'i uygulayın ve konteyner yapılandırmanızda hizmeti geçersiz kılın:

```yaml
services:
    Escalated\Symfony\Rendering\UiRendererInterface:
        class: App\Rendering\TwigUiRenderer
```

## Durum Geçişleri

Biletler bu geçişlerle bir durum makinesini takip eder:

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

## Test

```bash
vendor/bin/phpunit
```

## Lisans

MIT
