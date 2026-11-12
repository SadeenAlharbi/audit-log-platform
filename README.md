# Audit Log Hub · منصة Audit Log المركزية

منصة مركزية تستقبل سجلات التدقيق من كل أنظمة الشركة عبر REST API، وتحفظها بشكل **غير قابل للتعديل أو الحذف**، مع فصل كامل لبيانات كل مشروع، ولوحة تحكم عربية (Filament 5) للبحث والتصدير والإدارة.

**التقنيات:** Laravel 13 · PHP 8.4 · Filament 5 · MariaDB 11.8 · Redis · Horizon · Node 24 · Docker

---

## التشغيل

المتطلب الوحيد على الجهاز: **Docker Desktop**.

```bash
./bin/setup
```

السكربت آمن لإعادة التشغيل، ويقوم بالآتي:
1. ينشئ `.env` بكلمات مرور عشوائية.
2. يبني الصور ويثبّت الحزم.
3. يولّد مفتاح تشفير النسخ الاحتياطية (age).
4. يشغّل الخدمات وينفّذ `migrate --seed`.

لإنشاء أول Super Admin: عبّئ `ADMIN_EMAIL` و`ADMIN_PASSWORD` في `.env` ثم شغّل `make seed`. عند أول دخول يُطلب منك تفعيل المصادقة الثنائية (تطبيق Authenticator).

بعد ذلك يكفي `docker compose up -d`.

> **الترقية من نسخة الأسبوع الأول:** تغيّرت حسابات قاعدة البيانات (فصل حساب التشغيل عن حساب الصيانة)، وأُضيفت خدمات جديدة. ولا توجد بيانات حقيقية بعد، فابدأ بقاعدة نظيفة مرة واحدة:
> ```bash
> docker compose down -v && ./bin/setup
> ```

| الرابط | الوصف |
|---|---|
| http://localhost:8080/admin | لوحة التحكم |
| http://localhost:8080/api/v1 | الـ API ([التوثيق](http://localhost:8080/docs/api)) |
| http://localhost:8080/horizon | مراقبة الطوابير (Super Admin) |
| http://localhost:8080/telescope | Telescope (التطوير فقط) |

### الخدمات

| الخدمة | الدور |
|---|---|
| `app` | PHP 8.4-FPM |
| `nginx` | الواجهة على المنفذ 8080 |
| `mariadb` | MariaDB 11.8 مع Binary Logs، ومتاحة على `127.0.0.1:33060` |
| `redis` | الطوابير والكاش والجلسات والـ Rate limiting |
| `queue-worker` | Horizon: يخزّن السجلات المستلمة |
| `scheduler` | المهام المجدولة: الأقسام، الأرشفة، الاحتفاظ، النسخ، التنبيهات |
| `backup` | mariadb-backup كامل يوميًا وتزايدي كل ساعة، Binary Logs، تشفير age، رفع rclone (في الإنتاج)، اختبار استعادة أسبوعي |

> في التطوير يُحفظ الأرشيف والنسخ محليًا (`storage/app/archive` و`storage/app/backups` وvolume `backups`). في الإنتاج تُوجَّه إلى S3 خارج الخادم (Runbook §1).

### أوامر مفيدة

```bash
make help        # كل الأوامر
make ps          # حالة الخدمات والـ healthchecks
make demo        # بيانات تجريبية للمراجعة: 4 أنظمة وحساب لكل دور (التطوير فقط)
make test        # الاختبارات (قاعدة audit_testing)
make lint        # Pint
make analyse     # PHPStan / Larastan مستوى 6
./bin/doctor     # فحص شامل، والتقرير في storage/logs/doctor.log
make partitions  # أقسام audit_logs
make schema-dump # تحديث database/schema/schema.sql
```

---

## ربط نظام بالمنصة (باختصار)

1. من اللوحة: **المشاريع ← إنشاء**. يُنشأ الـ slug ومفتاح API، ويُعرض المفتاح **مرة واحدة فقط**.
2. أرسل السجلات:

```bash
curl -X POST https://audit.example.com/api/v1/logs \
  -H "Authorization: Bearer $AUDIT_API_KEY" -H "X-Project: erp" \
  -H "Content-Type: application/json" \
  -d '{"external_id":"order-1001-paid","event":"order.paid","action":"update",
       "occurred_at":"2026-11-11T10:00:00Z","subject":{"type":"order","id":"1001"},
       "old_values":{"status":"pending"},"new_values":{"status":"paid"}}'
```

3. مشاريع Laravel تستخدم الحزمة `company/audit-client` (ريبو مستقل).

التفاصيل الكاملة: [docs/integration-guide.md](docs/integration-guide.md). العقد الرسمي: [docs/openapi.yaml](docs/openapi.yaml).

---

## الوثائق

| الوثيقة | المحتوى |
|---|---|
| [docs/integration-guide.md](docs/integration-guide.md) | دليل الربط: المصادقة، التوقيع، الأمثلة (cURL / PHP / Node / Python / Laravel)، الأخطاء |
| [docs/openapi.yaml](docs/openapi.yaml) | عقد الـ API (OpenAPI 3.1)، ويُعرض في `/docs/api` |
| [docs/runbook.md](docs/runbook.md) | دليل التشغيل: النشر، النسخ، الاستعادة الكاملة والجزئية، تدوير المفاتيح، الحوادث |
| [docs/permissions-matrix.md](docs/permissions-matrix.md) | مصفوفة الأدوار والصلاحيات، وهي مختبرة آليًا |
| [docs/schema.md](docs/schema.md) · [database/schema/schema.sql](database/schema/schema.sql) | المخطط والـ ERD |
| [docs/decisions.md](docs/decisions.md) | القرارات التقنية وأسبابها |
| [docs/security-review.md](docs/security-review.md) | المراجعة الأمنية النهائية |
| [docs/plan-compliance.md](docs/plan-compliance.md) | كل متطلب في وثيقة الخطة، وأين نُفّذ، وكيف اختُبر |
| [docs/product/product-overview.pdf](docs/product/product-overview.pdf) | الوثيقة التعريفية بالمنتج: الهدف، والأدوار، ورحلة السجل، والشاشات |
| [docs/reports/](docs/reports/) | تقارير اختبار الاستعادة |

---

## الجودة

- **Pest:** 190 اختبارًا على MariaDB حقيقية (التقسيم والـ Triggers تحتاج المحرك الحقيقي).
- **التغطية:** طبقة الـ API 95% والمشروع كله 90% (الحد الأدنى 80%، ويفرضه `bin/coverage-api` في CI).
- **Pint + PHPStan مستوى 6:** بدون أخطاء.
- **CI:** [.github/workflows/ci.yml](.github/workflows/ci.yml)، يشغّل كل الفحوص داخل Docker، ويبني صور الإنتاج، ويفحص ملف OpenAPI.

## سياسة الـ Migrations

- Migration واحد لكل جدول، يحتوي الأعمدة والفهارس والقيود والـ Triggers كاملة.
- **قبل أول نشر:** يُعدَّل ملف الجدول الأصلي ثم يُشغَّل `make fresh`، ولا تُضاف Migrations من نوع alter.
- **بعد أول نشر:** تُقبل Migrations إضافية فقط، قابلة للتراجع، مع `down()` مختبر.
