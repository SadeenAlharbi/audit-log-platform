# Audit Log Hub - منصة Audit Log المركزية

منصة مركزية تستقبل سجلات التدقيق من أنظمة الشركة عبر REST API، وتحفظها بشكل غير قابل للتعديل، مع لوحة تحكم Filament.

**التقنيات:** Laravel 13 · PHP 8.4 · Filament 5 · MariaDB 11.8 · Redis · Node 24 · Docker

## التشغيل لأول مرة

المتطلب الوحيد على الجهاز هو Docker Desktop.

```bash
./bin/setup        # أو: make setup
```

السكربت يقوم بالآتي:
1. ينسخ `.env.docker.example` إلى `.env`.
2. يبني الصور ويشغّل الخدمات.
3. ينتظر تثبيت الحزم (`composer install` يتم تلقائيًا في أول تشغيل).
4. يولّد `APP_KEY`.
5. يشغّل `migrate --seed`.

لإنشاء أول Super Admin، عبّئ `ADMIN_EMAIL` و`ADMIN_PASSWORD` في `.env` قبل التشغيل، أو بعده ثم شغّل `make seed`.

التشغيل اليدوي بأمر واحد (plan §3):

```bash
docker compose up -d && docker compose exec app php artisan migrate --seed
```

## الخدمات

| الخدمة | الدور |
|---|---|
| `app` | PHP 8.4-FPM |
| `nginx` | الواجهة على `http://localhost:8080` |
| `mariadb` | MariaDB 11.8، متاحة للجهاز على `127.0.0.1:33060` |
| `redis` | الطوابير والكاش والجلسات والـ Rate limiting |
| `queue-worker` | معالجة الطوابير (`ingest`, `default`) |
| `scheduler` | المهام المجدولة |
| `backup` | النسخ الاحتياطي (يُفعَّل بـ `docker compose --profile backup up -d`) |

## أوامر مفيدة

```bash
make help         # كل الأوامر
make ps           # حالة الخدمات والـ healthchecks
make test         # الاختبارات (على قاعدة audit_testing)
make fresh        # إعادة بناء المخطط من الصفر (قبل أول نشر فقط)
make partitions   # عرض أقسام audit_logs وإضافة الأشهر القادمة
make schema-dump  # توليد database/schema/schema.sql
```

## الوثائق

- [docs/schema.md](docs/schema.md): المخطط، الـ ERD، وقائمة مراجعة التجميد
- [docs/decisions.md](docs/decisions.md): القرارات التقنية وأسبابها

## سياسة الـ Migrations (إلزامية)

- Migration واحد لكل جدول، يحتوي الأعمدة والفهارس والقيود والـ Triggers كاملة.
- **قبل أول نشر:** لا يُضاف Migration من نوع `alter` أو `add_column` أو `rename`. أي تعديل يتم في ملف الجدول الأصلي ثم `make fresh`.
- **بعد أول نشر:** تُقبل Migrations إضافية فقط، بشرط أن تكون قابلة للتراجع وأن يكون `down()` مكتوبًا ومختبرًا.

## خطة التسليم

| الأسبوع | المخرجات | الحالة |
|---|---|---|
| 1 | Docker، Laravel 13، MariaDB، المخطط، Migrations، Models، التقسيم | جاهز للمراجعة |
| 2 | الـ API (ingest + query)، المصادقة، الحماية، الاختبارات | |
| 3 | Filament: Projects, API Keys, Logs, Dashboard, Users, Roles | |
| 4 | النسخ والأرشفة، التوثيق، حزمة `audit-client`، CI، المراجعة الأمنية | |
