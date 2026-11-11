# دليل التشغيل · Runbook

دليل عملي لمن يشغّل المنصة: النشر، والنسخ الاحتياطي، والاستعادة الكاملة والجزئية، وتدوير المفاتيح، والتعامل مع التنبيهات.

**الأهداف:**
- **RPO = ساعة واحدة** (أقصى فقد مقبول للبيانات).
- **RTO = ساعتان** (وقت الاستعادة).

> كل الأوامر تُنفَّذ من مجلد المشروع على الخادم. في الإنتاج يُضاف `-f docker-compose.yml -f docker-compose.prod.yml` لكل أمر `docker compose`. لتسهيل ذلك:
>
> ```bash
> alias dc='docker compose -f docker-compose.yml -f docker-compose.prod.yml'
> ```

---

## 1. النشر (Deployment)

### 1.1 المتطلبات

- خادم Linux عليه Docker Engine 25+ وCompose v2.
- Reverse proxy أو load balancer يُنهي TLS (HTTPS إلزامي)، ويمرّر `X-Forwarded-Proto`.
- Bucket خارج الخادم (S3، أو MinIO على خادم آخر) للأرشيف والنسخ، مع تفعيل Versioning.
- خادم بريد (SMTP) و/أو Slack Incoming Webhook للتنبيهات.

### 1.2 أول نشر

```bash
git clone <repo> audit-log-platform && cd audit-log-platform
cp .env.docker.example .env
```

عدّل `.env`:

| المتغير | القيمة في الإنتاج |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://audit.example.com` |
| `APP_KEY` | `docker run --rm audit-log-platform/app php artisan key:generate --show` بعد البناء |
| `TRUSTED_PROXIES` | عناوين الـ proxy |
| `LOG_LEVEL` | `info` |
| `TELESCOPE_ENABLED` | `false` (الحزمة غير موجودة أصلًا في صورة الإنتاج) |
| `DB_*_PASSWORD`, `AWS_SECRET_ACCESS_KEY` | أسرار عشوائية قوية: `openssl rand -hex 24` |
| `AWS_*`, `AUDIT_ARCHIVE_*`, `BACKUP_*` | بيانات الـ bucket الحقيقي. `BACKUP_S3_PROVIDER=AWS` لـ S3، و`AWS_USE_PATH_STYLE_ENDPOINT=false` |
| `BACKUP_AGE_RECIPIENT` | المفتاح العام للتشفير (انظر 1.3) |
| `ALERT_MAIL_TO`, `ALERT_SLACK_WEBHOOK_URL`, `MAIL_*` | قنوات التنبيه |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | أول Super Admin. احذفهما من `.env` بعد أول seed |

ثم:

```bash
dc build
dc up -d
dc exec app php artisan migrate --seed --force
dc exec app php artisan audit:partitions
dc ps            # كل الخدمات healthy
```

### 1.3 مفتاح تشفير النسخ (age)

```bash
age-keygen -o backup-age-identity.txt
grep 'public key' backup-age-identity.txt    # تضعه في BACKUP_AGE_RECIPIENT
```

**المفتاح الخاص لا يبقى على الخادم.** يُحفظ في خزنة أسرار الشركة (Vault أو مدير كلمات مرور) مع نسخة ثانية لدى مسؤول آخر. بدونه لا يمكن استعادة أي نسخة مشفّرة.

### 1.4 تقوية قاعدة البيانات في الإنتاج

1. **فصل حساب الـ Migrations عن حساب التشغيل** (decisions D6-2):
   - أنشئ `audit_migrator` بكل الصلاحيات، واستخدمه فقط لـ `migrate` (`DB_USERNAME=audit_migrator dc exec app php artisan migrate --force`).
   - حساب التطبيق `audit_app` يكفيه:
     ```sql
     GRANT SELECT, INSERT, UPDATE, DELETE ON audit_log.* TO 'audit_app'@'%';
     ```
     الـ Triggers تمنع UPDATE وDELETE على الجداول المحمية.
   - `ALTER` على `audit_logs` (لإضافة الأقسام وحذفها) يُمنح لحساب منفصل يشغّل `audit:partitions` و`audit:prune` فقط، أو يُنفَّذان بحساب الـ migrator من الـ scheduler.
2. لا يوجد منفذ لقاعدة البيانات على الخادم (`ports: !reset []` في `docker-compose.prod.yml`).
3. `innodb_buffer_pool_size` يُضبط على 60–70% من الذاكرة في `docker/mariadb/conf.d/audit.cnf`.

### 1.5 التحديثات

```bash
git pull
dc build
dc up -d                                     # Horizon يُنهي الـ jobs الجارية خلال 60 ثانية
dc exec app php artisan migrate --force      # بعد أول نشر: Migrations إضافية قابلة للتراجع فقط
dc exec app php artisan horizon:terminate
```

**قبل كل تحديث:** `dc run --rm backup full`.

---

## 2. ما يعمل تلقائيًا

| المهمة | التوقيت (UTC) | أين |
|---|---|---|
| تجهيز أقسام الأشهر القادمة (`audit:partitions`) | يوميًا 01:10 | scheduler |
| الأرشيف المتزايد Write-Ahead (`audit:archive`) | كل ساعة :05 | scheduler |
| الاحتفاظ والحذف (`audit:prune`): أرشفة ثم حذف | يوميًا 02:30 | scheduler |
| نسخة كاملة (mariadb-backup) | يوميًا 02:00 | backup |
| نسخة تزايدية + نسخ Binary Logs | كل ساعة | backup |
| اختبار استعادة | أسبوعيًا (الجمعة 03:00) | backup |
| نسخ ملفات التطبيق `.env` + storage (`backup:run --type=app`) | يوميًا 03:00 | scheduler |
| سياسة الاحتفاظ للنسخ: يومي 14، أسبوعي 8، شهري 12 (`backup:clean`) | يوميًا 04:00 | scheduler |
| استيراد نتائج النسخ والتنبيه (`backup:monitor`) | كل 5 دقائق | scheduler |
| تنبيه بالمفاتيح القريبة من الانتهاء | يوميًا 08:00 | scheduler |
| تقرير الصلاحيات الحساسة | شهريًا | scheduler |
| مراجعة الصلاحيات الكاملة | كل 3 أشهر | scheduler |
| مراقبة تراكم الطوابير | كل 5 دقائق | scheduler + Horizon |

كل عملية نسخ أو أرشفة أو استعادة تُسجَّل في `backup_runs`، وتظهر في **اللوحة ← التشغيل ← النسخ الاحتياطي** مع زر التشغيل اليدوي.

### مسارات الحفظ

| ماذا | أين |
|---|---|
| نسخ القاعدة | محليًا في volume `backups` (آخر 3 أيام)، وخارج الخادم في `${BACKUP_RCLONE_REMOTE}/db/{full,incremental,binlog}/` (مشفّرة `.age`) |
| ملفات التطبيق | disk `backups` تحت `app/YYYY/MM/DD/` (مشفّرة) |
| أرشيف السجلات | disk `archive` تحت `logs/{project}/YYYY/MM/DD/{firstId}-{lastId}.jsonl.gz`، ومع كل ملف manifest فيه SHA-256 |

هذه ثلاث نسخ على وسيطين مختلفين، إحداها خارج الخادم (قاعدة 3-2-1).

---

## 3. الاستعادة

### 3.1 استعادة كاملة للقاعدة (أحدث نقطة)

**المدة المتوقعة:** دقائق لكل 10GB. آخر اختبار مسجّل في `docs/reports/`.

1. أوقف الكتابة:
   ```bash
   dc stop nginx queue-worker scheduler
   ```
   الأنظمة المرسِلة تعيد المحاولة تلقائيًا، والحزمة تحتفظ بالسجلات في الـ outbox.
2. أحضر السلسلة: آخر full وكل التزايديات بعدها. إن لم تكن محليًا، انسخها من التخزين الخارجي:
   ```bash
   rclone copy offsite:audit-backups/db/full/<full-YYYYmmddTHHMMSSZ>.tar.gz.age ./restore/
   rclone copy offsite:audit-backups/db/incremental/ ./restore/ --include "incremental-<بعد الـ full>*"
   ```
3. فك التشفير والضغط:
   ```bash
   for f in restore/*.age; do age -d -i backup-age-identity.txt -o "${f%.age}" "$f"; done
   mkdir -p restore/full && tar -xzf restore/full-*.tar.gz -C restore/full
   ```
   فك كل تزايدية بنفس الطريقة في `restore/inc-N`.
4. حضّر السلسلة بالترتيب، بنفس إصدار MariaDB:
   ```bash
   docker run --rm -v "$PWD/restore:/r" mariadb:11.8 mariadb-backup --prepare --target-dir=/r/full
   docker run --rm -v "$PWD/restore:/r" mariadb:11.8 mariadb-backup --prepare --target-dir=/r/full --incremental-dir=/r/inc-1
   ```
   كرّر الأمر الثاني لكل تزايدية بالترتيب.
5. استبدل البيانات:
   ```bash
   dc stop mariadb
   docker run --rm -v audit-log-platform_mariadb-data:/var/lib/mysql -v "$PWD/restore:/r" mariadb:11.8 \
     sh -c 'rm -rf /var/lib/mysql/* && mariadb-backup --copy-back --target-dir=/r/full && chown -R mysql:mysql /var/lib/mysql'
   dc up -d mariadb
   ```
6. أكمل إلى آخر لحظة بالـ Binary Logs (القسم 3.2)، ثم:
   ```bash
   dc up -d
   ```
7. تحقق:
   - `dc exec app php artisan audit:partitions`.
   - عدد السجلات مقارنةً بآخر `max_audit_id` في `backup_runs.meta`.
   - `curl https://audit.example.com/api/v1/health`.

### 3.2 الاستعادة إلى نقطة زمنية (PITR) بالـ Binary Logs

كل نسخة تحمل `mariadb_backup_binlog_info` (اسم ملف الـ binlog والموضع لحظة النسخ).

بعد تحضير آخر تزايدية، يحتوي ملف `restore/full/mariadb_backup_binlog_info` على الملف والموضع الذي تبدأ منه إعادة التشغيل:

```bash
read BINLOG POS GTID < restore/full/mariadb_backup_binlog_info
# أحضر ملفات mariadb-bin.NNNNNN من BINLOG فما بعد من offsite:audit-backups/db/binlog/ وفك تشفيرها وضغطها
TZ=UTC mariadb-binlog --start-position="$POS" --stop-datetime="2026-11-11 09:59:59" \
  mariadb-bin.000123 mariadb-bin.000124 | dc exec -T mariadb mariadb -uroot -p"$DB_ROOT_PASSWORD"
```

- `--stop-datetime` لحظة **قبل** الحادث (حذف خاطئ أو تلف)، **بتوقيت UTC**. لهذا يُستخدم `TZ=UTC`: بدونه يفسّر `mariadb-binlog` الوقت بالمنطقة الزمنية المحلية.
- مرّر ملفات `mariadb-bin.NNNNNN` فقط، وليس ملفات `.idx` (فهرس GTID في MariaDB 11).

**ملاحظة مهمة:** `mariadb-backup --prepare --incremental-dir` **يستهلك** ملفات `.delta` من مجلد التزايدية. حضّر دائمًا على **نسخ** من المجلدات، لا على النسخ الأصلية.

### 3.3 استعادة سجلات مشروع واحد (استعادة جزئية)

من اللوحة: **النسخ الاحتياطي ← «استعادة سجلات مشروع من الأرشيف»** (صلاحية `backups.restore`). أو:

```bash
dc exec app php artisan audit:restore-archive erp --from=2026-09-01 --to=2026-09-30 --dry-run   # عدّ فقط
dc exec app php artisan audit:restore-archive erp --from=2026-09-01 --to=2026-09-30
```

- يتحقق من SHA-256 لكل ملف قبل الاستخدام.
- يحتفظ بالـ `id` و`uid` الأصليين.
- يتجاوز الموجود مسبقًا، فتشغيله مرتين آمن.
- يُسجَّل في `backup_runs` كـ `restore`.

### 3.4 إعادة بناء `audit_logs` من الأرشيف فقط

إذا تلفت القاعدة والنسخ معًا:

1. شغّل migrate على قاعدة فارغة.
2. أعد إنشاء المشاريع بنفس الـ slug.
3. شغّل `audit:restore-archive <slug> --from=<أقدم تاريخ> --to=<الآن>` لكل مشروع.

الأرشيف متأخر عن القاعدة بساعة على الأكثر (الأرشفة كل ساعة).

### 3.5 اختبار الاستعادة

- **تلقائي:** أسبوعيًا.
- **يدوي:** `dc exec app php artisan backup:restore-test --wait=10`، أو من زر اللوحة.

الاختبار يستعيد آخر full مع تزايدياته في خادم MariaDB مؤقت داخل حاوية الـ backup، ثم يتحقق من:
- `CHECK TABLE` لكل جدول.
- عدد السجلات.
- أن `max(id)` لا يقل عما سُجّل وقت النسخ.
- عدد الأقسام.

التقرير يُحفظ في `storage/app/backup-spool/reports/`، والنتيجة في `backup_runs`. **فشل الاختبار يرسل تنبيهًا فوريًا.**

---

## 4. مفاتيح API

| العملية | الخطوات |
|---|---|
| **إنشاء** | المشروع ← المفاتيح ← إنشاء: الاسم، البيئة، الـ scopes، التوقيع، قائمة IP. المفتاح والسر يُعرضان مرة واحدة |
| **تدوير** (إلزامي كل 90 يومًا) | زر «تدوير» يُنشئ مفتاحًا جديدًا بنفس الإعدادات. القديم يعمل 24 ساعة إضافية ثم ينتهي. حدّث الأسرار في النظام المرسِل خلال المهلة |
| **إلغاء فوري** (اشتباه بتسريب) | زر «إلغاء». الرفض يبدأ مع الطلب التالي. أنشئ مفتاحًا بديلًا، وراجع «المحاولات المرفوضة» واستعلم سجلات المشروع عن الفترة المشبوهة |
| **قائمة IP** | Security Officer أو Project Owner: «إدارة عناوين IP» |

كل عملية تُسجَّل في «تدقيق المنصة».

---

## 5. التنبيهات وما تفعله

| التنبيه | السبب المحتمل | الإجراء |
|---|---|---|
| **Backup failed / Backup is late** | فشل mariadb-backup أو الرفع، أو حاوية backup متوقفة | `dc logs backup`، ثم `dc ps backup`، ثم شغّل يدويًا `dc run --rm backup full` |
| **Backup size is abnormal** | تغيّر الحجم أكثر من 50% عن المعتاد | تحقق من حجم البيانات، أو من نسخة ناقصة |
| **Restore test failed** | نسخة تالفة أو سلسلة ناقصة | خذ full جديدة فورًا، وراجع التقرير |
| **Queue backlog / wait time too long** | Horizon متوقف أو القاعدة بطيئة | `/horizon`، ثم `dc logs queue-worker`، ثم `dc restart queue-worker` |
| **Audit logs could not be stored** | Job فشل بعد 5 محاولات | أصلح السبب (القاعدة، أو قسم مفقود)، ثم `dc exec app php artisan queue:retry all`. السجلات محفوظة في `failed_jobs` ولا تضيع |
| **Repeated rejected API requests** | مفتاح مسرّب، أو نظام مضبوط خطأ، أو هجوم | «المحاولات المرفوضة»: حدّد العنوان والسبب. ألغِ المفتاح، أو أضف قائمة IP، أو احجب العنوان في الـ proxy |

---

## 6. صيانة دورية

| الوتيرة | المهمة |
|---|---|
| يوميًا | نظرة على لوحة التحكم: الحجم اليومي، والدفعات الفاشلة، والمحاولات المرفوضة |
| أسبوعيًا | تقرير اختبار الاستعادة الأخير، و`failed_jobs` = 0 |
| شهريًا | تقرير الصلاحيات الحساسة. وتأكد أن `p_future` فارغ (`make partitions`) |
| كل 3 أشهر | Access Review: راجع ملف CSV، وسحب الصلاحيات غير اللازمة يُسجَّل تلقائيًا. ثم تمرين استعادة كاملة يدويًا (القسم 3.1) على خادم منفصل |
| عند ترقية MariaDB | صورة الـ backup يجب أن تطابق إصدار الخادم. ابنِ الصورتين معًا ثم خذ full جديدة |
