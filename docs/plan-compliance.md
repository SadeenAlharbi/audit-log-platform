# مطابقة الخطة · Plan Compliance (v1.1)

كل متطلب في «وثيقة متطلبات وخطة تطوير منصة Audit Log المركزية» (الإصدار 1.1)، وأين نُفّذ، وكيف يُثبت. ✓ = منفّذ ومختبر، ↔ = منفّذ ببديل مكافئ موثّق بقرار.

## القيود الإلزامية

| المتطلب | الحالة | الدليل |
|---|---|---|
| Laravel 13 + PHP 8.4 فقط | ✓ | `composer.json` (`"php": "^8.4"`، `"laravel/framework": "^13.0"`)، `Dockerfile` (`php:8.4-fpm`). الـ CI يفشل إن تغيّرت |
| Node 24+ | ✓ | `package.json` engines، `.nvmrc`، ومرحلة `node-tools` من `node:24-alpine` في الـ `Dockerfile`. الـ CI يفشل إن تغيّرت |
| Filament 5 | ✓ | `filament/filament ^5.0` |
| Migrations نظيفة ونهائية | ✓ | 18 Migration، واحد لكل جدول (الأخير `saml2_tenants` للدخول الموحد، D15) |

## §3 Docker

| المتطلب | الحالة | الدليل |
|---|---|---|
| الخدمات: app، nginx، mariadb، redis، queue-worker، scheduler، backup | ✓ | `docker-compose.yml` (التخزين الخارجي S3 في الإنتاج، ومحلي في التطوير) |
| Dockerfile متعدد المراحل dev/prod، الإضافات pdo_mysql/redis/intl/bcmath/zip/opcache، وأصول بـ node:24-alpine | ✓ | `Dockerfile` (base / dev / prod / web / node-tools). لا توجد أصول أمامية تحتاج بناء (Filament يشحن أصوله)، فمرحلة `node-tools` (`node:24-alpine`) تشغّل أدوات Node: فحص OpenAPI (`make openapi-lint` والـ CI)، وأي بناء أصول يُضاف لاحقًا مكانه هنا |
| `.env.docker.example` | ✓ | فيه كل المتغيرات |
| Volumes دائمة لـ MariaDB وRedis | ✓ | `mariadb-data` و`redis-data` |
| Healthchecks لكل خدمة | ✓ | كل الخدمات الدائمة |
| تشغيل بأمر واحد + `bin/setup` / Makefile | ✓ | `bin/setup`، `Makefile`، `bin/doctor` |

## §4 المخطط

| المتطلب | الحالة | الدليل |
|---|---|---|
| تصميم ومراجعة قبل Migrations، وتجميد | ✓ | `docs/schema.md`، `docs/decisions.md` |
| Migration لكل جدول مع القيود | ✓ | `database/migrations/`. استثناء: لا FK على الجدول المقسَّم (D1) |
| تقسيم شهري حسب `occurred_at` | ✓ | `AuditLogPartitionManager`، `audit:partitions`، و`AuditLogsPartitioningTest` |
| Trigger يرفض UPDATE/DELETE إلا لحساب الأرشفة | ✓ | `AppendOnlyTriggers`، `AppendOnlyTest`، `ArchiverAndPartitionsTest` |
| `(project_id, external_id)` يمنع التكرار | ✓ | `ingestion_keys` (D2)، `IdempotencyTest` |
| Seeders مرجعية idempotent | ✓ | `SeedersTest` |
| `schema.sql` أو ERD | ✓ | `database/schema/schema.sql` + ERD كامل `docs/erd/erd.pdf` (`./bin/erd`) + ملخص في `docs/schema.md` |
| InnoDB page compression وأعمدة JSON | ✓ | `PAGE_COMPRESSED=1` على `audit_logs` |

## §5 الربط

| المتطلب | الحالة | الدليل |
|---|---|---|
| الـ API هو العقد، وموثّق في OpenAPI | ✓ | `docs/openapi.yaml`، `/docs/api` |
| حزمة `company/audit-client`: ريبو مستقل، PHP 8.2+، Laravel 11/12/13 | ✓ | ريبو `audit-client` (الإصدار الحالي v1.2.0) |
| إعدادات endpoint/project/key/secret/queue/timeout/retry/enabled | ✓ | `config/audit-client.php` |
| إرسال غير متزامن بطابور + Exponential backoff | ✓ | `Jobs/SendAuditLogs`، واختبارات الحزمة |
| تجميع إلى `/logs/batch` | ✓ | `Support/Buffer` |
| Facade، Trait `Auditable`، Listener للمصادقة، Middleware لـ request_id | ✓ | 68 اختبارًا في الحزمة |
| توقيع HMAC تلقائي وإخفاء الحقول | ✓ | `Security/Signer`، `Security/Masker`. التوقيع مطابق لتوقيع المنصة: 200 من 200 مدخلًا عشوائيًا، واختبار end-to-end حقيقي |
| outbox + `audit:flush` | ✓ | `DatabaseOutbox` / `FileOutbox`، `FlushCommand` |
| نشر بمستودع Composer خاص + SemVer + CHANGELOG | ✓ | مستودع GitHub خاص SadeenAlharbi/audit-client بإصدارات وCHANGELOG، ويُثبَّت عبر Composer كمستودع `vcs` (D17). Satis اختياري: `docs/publishing.md` |
| اختبارات بـ `Http::fake()` | ✓ | `tests/` في الحزمة |
| أمثلة cURL / PHP / JS / Python | ✓ | `docs/integration-guide.md` وصفحة المشروع في اللوحة |
| Onboarding: slug ومفتاح تلقائي، يُعرض مرة واحدة، hash، أكثر من مفتاح، تدوير وإلغاء، صفحة دليل الربط، زر سجل تجريبي يمرّ عبر الـ API كاملة بمفتاح مؤقت | ✓ | `FilamentPagesTest`، `FilamentActionsTest` |

## §6 الـ API

| المتطلب | الحالة | الدليل |
|---|---|---|
| `/api/v1/` والمسارات الخمسة (+ `/batches/{uid}`) | ✓ | `routes/api.php`. اختبار يمنع وجود مسار غير موثّق |
| Form Request ثم Queue ثم Job ثم 202 | ✓ | `IngestTest` («answers before storing») |
| Cursor pagination والفلاتر | ✓ | `QueryTest` |
| أخطاء JSON موحّدة | ✓ | `ApiError`، `ErrorFormatTest` |

## §7 حماية الـ API

| المتطلب | الحالة | الدليل |
|---|---|---|
| Bearer + X-Project، والتحقق من hash/scope/الانتهاء/الإلغاء | ✓ | `AuthenticationTest` |
| HMAC-SHA256 + X-Timestamp، ورفض ما هو أقدم من 5 دقائق ومنع إعادة الإرسال | ✓ | `SignatureTest` |
| Rate limit لكل مفتاح عبر Redis | ✓ | `RateLimitAndHealthTest` |
| قائمة IP اختيارية | ✓ | `AuthenticationTest` |
| HTTPS إلزامي وتعطيل CORS | ✓ | `RequireHttps`، `config/cors.php` |
| حد 1MB ورفض الحقول غير المعرّفة | ✓ | `IngestTest` |
| Masking | ✓ | D8، و`IngestTest` و`QueryTest` |
| لا تعديل ولا حذف عبر الـ API | ✓ | 405 لـ PUT/PATCH/DELETE |
| تسجيل المحاولات الفاشلة + تنبيه عند التكرار | ✓ | كل محاولة: صف مستقل لأول 30 في الدقيقة لكل IP وسبب، وصف ملخص بالعدد لما زاد (D21). `SecurityOpsTest`، `RateLimitAndHealthTest` |

## §8 لوحة Filament

| المتطلب | الحالة | الدليل |
|---|---|---|
| Projects: إنشاء وتعديل ومفاتيح وإحصائيات | ✓ | `ProjectResource` + `ApiKeysRelationManager` + `MembersRelationManager` |
| Audit Logs: قراءة فقط، فلاتر، بحث، Diff، تصدير CSV/JSON | ✓ | `AuditLogResource`، `ValueDiff`، `AuditLogExporter` |
| Dashboard widgets | ✓ | `PlatformStats`، `LogVolumeChart`، `TopEventsTable`، `ActiveProjectsTable` |
| Security Events، Backups (مع تشغيل يدوي)، Users & Roles | ✓ | `FilamentPagesTest` |
| Global Scope حسب المشاريع المصرّح بها | ✓ | `ProjectAccessScope`، `RoleMatrixTest` |
| واجهة عربية RTL | ✓ | `APP_LOCALE=ar`، وكل التسميات بالعربية |

## §9 الاحتفاظ والأرشفة

| المتطلب | الحالة | الدليل |
|---|---|---|
| `audit:prune` حسب `retention_days` | ✓ | D3، `RetentionTest` |
| أرشفة JSONL مضغوطة إلى S3/MinIO قبل أي حذف | ✓ | `LogArchiver` (watermark)، `ArchiveTest` |
| عدم الحذف الفوري | ✓ | الحذف لا يتجاوز الـ watermark، وحساب الأرشفة فقط |
| استعادة سجلات مشروع واحد | ✓ | `audit:restore-archive`، وزر في اللوحة. النطاق المسترجَع محمي من الحذف الليلي (D18، `RetentionTest`). تقرير الاستعادة §5 |

## §10 الجودة

| المتطلب | الحالة | الدليل |
|---|---|---|
| Pest للـ API: مصادقة، توقيع، rate limit، idempotency، batch | ✓ | `tests/Feature/Api` |
| اختبارات سياسات الوصول في Filament | ✓ | `tests/Feature/Dashboard` |
| Pint + PHPStan مستوى 6 | ✓ | بدون أخطاء |
| CI داخل Docker | ✓ | `.github/workflows/ci.yml` |
| تغطية 80%+ لطبقة الـ API | ✓ | `bin/coverage-api`: 95.3% (والمشروع كله 91.3%)، تقرير `docs/reports/coverage-2026-11-16.md` |

## §11 المراقبة

| المتطلب | الحالة | الدليل |
|---|---|---|
| JSON logging | ✓ | قناة `json` (`LOG_STACK=json`) |
| Telescope للتطوير فقط | ✓ | D10 |
| Horizon وتنبيه تراكم الطوابير | ✓ | `HorizonServiceProvider` (LongWaitDetected)، `queue:monitor` |
| تنبيهات Email/Slack: فشل النسخ، تراكم الطابور، تكرار الرفض | ✓ | `AlertNotifier`، `PlatformAlert`، `JobsAndAlertsTest` |

## §12 النسخ الاحتياطي

| المتطلب | الحالة | الدليل |
|---|---|---|
| قاعدة 3-2-1 | ✓ | محلي (volume)، وخارجي (S3/MinIO)، والأرشيف مستقل عن نسخة القاعدة |
| Full يومي، Incremental كل ساعة، Binary Logs | ✓ | حاوية `backup` |
| أرشيف JSONL شهري مستقل + Write-Ahead Archive | ↔ | أرشفة يومية بنفس الصيغة (D19): أقدم سجل غير مؤرشف عمره يوم بدل شهر |
| RPO ساعة / RTO ساعتان | ✓ | تقرير الاستعادة (PITR مطابق، والاستعادة 7 ثوانٍ لهذا الحجم) |
| mariadb-backup في حاوية مستقلة، log_bin مع expire_logs_days | ✓ | `docker/backup`، `audit.cnf` |
| spatie/laravel-backup لملفات التطبيق | ↔ | بديل مكافئ مشفّر بـ age وبنفس أسماء الأوامر (D7) |
| تشفير قبل الرفع (age)، والمفتاح خارج الخادم | ✓ | `bin/setup` + Runbook §1.3 |
| `backup:run` / `clean` / `monitor` + سياسة 14/8/12 | ✓ | `BackupTest` |
| `backup:restore-test` أسبوعيًا مع Checksum | ✓ | `backup.sh restore-test` على السلسلة المحلية، والنسخة الخارجية مُتحقَّق منها بايتًا ببايت عند الرفع وتمرين ربع سنوي (D13) |
| تنبيه عند فشل النسخ أو الحجم غير الطبيعي أو فشل الاختبار | ✓ | `backup:monitor` |
| Runbook للاستعادة الكاملة والجزئية | ✓ | `docs/runbook.md` |
| `backup_runs` في Filament | ✓ | صفحة «النسخ الاحتياطي» |

## §13 الصلاحيات

| المتطلب | الحالة | الدليل |
|---|---|---|
| RBAC بـ spatie، مستويان، الأدوار الخمسة، الصلاحيات التفصيلية | ✓ | `docs/permissions-matrix.md`، `RoleMatrixTest` |
| Policies لكل Model في Filament والـ API | ✓ | `app/Policies` للوحة، ونطاق المفتاح وربطه بمشروع في الـ API (D14) |
| Global Scope على AuditLog وProject | ✓ | `ProjectAccessScope` (ويشمل ProjectApiKey) |
| scopes للمفاتيح ومنع الجمع إلا بقرار صريح | ✓ | `FilamentPagesTest` |
| منع التعديل في الـ Policy والـ Trigger | ✓ | `RoleMatrixTest`، `AppendOnlyTest` |
| 2FA إلزامي لـ Super Admin وSecurity Officer | ✓ | `FilamentPagesTest` |
| SSO (SAML/OIDC) «إن وُجد» | ✓ | SAML 2.0 بجانب كلمة المرور (D15)، مع المصادقة الثنائية بعده، وKeycloak للتطوير. `SamlTest` يرسل ردودًا موقّعة حقيقية |
| انتهاء الجلسة، قفل الحساب، كلمات مرور قوية، تدوير المفاتيح كل 90 يومًا | ✓ | `SESSION_LIFETIME=30`، `SecurityOpsTest` |
| Self-Audit في platform_audit | ✓ | `PlatformAuditObserver`، `RecordPermissionChanges` |
| تقرير دوري بالصلاحيات الحساسة + Access Review كل 3 أشهر | ✓ | `access:review` |
| اختبارات: كل دور يرى ما يُسمح له فقط، مع حالات سلبية | ✓ | `RoleMatrixTest` |

## §15 المخرجات

| المخرج | المكان |
|---|---|
| الريبو مع Docker بأمر واحد | `./bin/setup` |
| OpenAPI + README | `docs/openapi.yaml`، `README.md`، `docs/integration-guide.md` |
| حزمة audit-client بريبو مستقل | `audit-client/` (v1.2.0، الوسوم v1.0.1 و v1.1.0 و v1.2.0) |
| schema.sql/ERD + Migrations نظيفة | `database/schema/schema.sql`، `docs/erd/erd.pdf`، `docs/schema.md` |
| Runbook | `docs/runbook.md` |
| مصفوفة الأدوار موثقة ومختبرة | `docs/permissions-matrix.md` + `RoleMatrixTest` |
| تغطية 80%+ للـ API | `docs/reports/coverage-2026-11-16.md` (95.3%) |
| تقرير اختبار استعادة ناجح | `docs/reports/restore-test-2026-11-11.md`، وتمرين النسخة الخارجية `docs/reports/offsite-drill-2026-11-16.md` (ناجح) |

## تحديث 2026-11-12: مراجعة مستقلة ثانية

راجع مراجع مستقل الكود مقابل الخطة بندًا بندًا. ما وجده وأُصلح:

| الملاحظة | الإصلاح | الاختبار |
|---|---|---|
| فشل التشفير أو الرفع في `backup.sh` قد يُسجَّل نجاحًا | فحص صريح لكل خطوة، والاحتفاظ بالملف المشفّر محليًا عند فشل الرفع، وتعليم دفعة الـ binlog فاشلة إن فشل أي ملف | اختبار يدوي بـ rclone وهمي يفشل وينجح |
| نسخ ملفات التطبيق تفشل في الإنتاج لغياب `.env` داخل الحاوية | `BACKUP_ENV_FILE` وتركيب الملف للقراءة فقط في `docker-compose.prod.yml`، ورفض صريح إن غاب | `BackupTest` (اختباران) |
| السجلات المسترجعة من الأرشيف تُحذف في نفس الليلة | حماية النطاق المسترجَع (D18) | `RetentionTest` |
| لا «أرشفة لينة» قبل الحذف | فترة سماح 7 أيام بعد الأرشفة (D18) | `RetentionTest` |
| زر السجل التجريبي لا يمر بالـ API | طلب حقيقي موقّع عبر نفس المسار والـ Middleware بمفتاح مؤقت يُلغى فورًا | `FilamentActionsTest` |
| «آخر استلام» محسوب بوقت الحدث | بوقت الوصول (آخر استخدام لمفتاح كتابة) | — |
| لا اختبار لعزل التصدير بين المشاريع | اختبار CSV وJSON: لا سجلات لمشروع آخر، والقيم الحساسة مخفية | `FilamentPagesTest` |
| تصفية بوسم واحد فقط | `tags=a,b` (كل الوسوم) | `QueryTest` |
| الرفض بحد الـ IP وأخطاء 413/415 لا تُسجَّل | تُسجَّل في `security_events` (مع أخذ العينات) | `RateLimitAndHealthTest`، `IngestTest` |
| الاستعادة الكاملة اليدوية لا تُسجَّل | `backup:record-restore` وخطوة في الـ Runbook | `BackupTest` |
| `schema.sql` مولَّد بأقسام شهر واحد | أُعيد توليده بـ 13 شهرًا، والجداول مطابقة للنسخة المجمّدة | — |
| `SESSION_SECURE_COOKIE` غير موجود في `.env.docker.example` | أُضيف | — |
| `composer.lock` قديم | حُدّث بالحزم الجديدة (`aws`، `telescope`) | — |

## تحديث 2026-11-16: إغلاق البنود المتبقية

| البند | ما تم |
|---|---|
| الدخول الموحد SSO (§13) | SAML 2.0 بجانب كلمة المرور (D15)، للحسابات الموجودة فقط، مع المصادقة الثنائية بعده دائمًا. Keycloak للتطوير (`make sso`) |
| اعتماد المخطط والقرارات (§4، §14) | اعتمدته صاحبة المشروع في 2026-11-15، والقرارات D1–D19 معتمدة |
| نشر الحزمة (§5) | مستودع GitHub خاص بإصدارات SemVer (D17) |
| تقرير الاستعادة من النسخة الخارجية (§12، §15) | `make offsite-drill` نجح: `docs/reports/offsite-drill-2026-11-16.md` (D13) |
| التحقق من النسخة الخارجية بعد الرفع | صار يقارن SHA-256 للنسخة المنزّلة؛ `rclone check` كان يرفض الملفات المفردة |
| تتبّع تغيير الإعدادات (§13) | `audit:config-check` كل ساعة يسجّل في `platform_audit` (D16) |
| الأرشفة | يومية بدل كل ساعة (D19): ملف لكل مشروع في اليوم، وتنبيه بعد 26 ساعة تأخير |
| إعادة محاولة الاستلام | طابور الاستلام على اتصال مستقل `redis-ingest` بمهلة 3 دقائق بدل ساعة |
| المصادقة الثنائية | الـ QR ثابت حتى يكتمل الإعداد، وتنبيه عند اختلاف ساعة الخادم، وفحص في `bin/doctor` |

## تحديث 2026-11-16: مراجعة ثالثة مقابل الخطة

| الملاحظة | الإصلاح |
|---|---|
| §2 و§3 يطلبان صورة `node:24-alpine` في Docker، والـ `Dockerfile` لم يكن فيه مرحلة Node (كان فحص OpenAPI يعمل بـ Node على جهاز الـ CI) | مرحلة `node-tools` من `node:24-alpine`، والـ CI وأمر `make openapi-lint` يفحصان OpenAPI داخلها، وحارس الـ CI يتأكد من وجودها |
| ERD كان ملخصًا للعلاقات فقط | ERD كامل بالأعمدة والمفاتيح `docs/erd/erd.pdf`، يُولَّد من `schema.sql` بـ `./bin/erd` |
| دليل اختبار الواجهة قبل التسليم | `docs/acceptance-testing.md` |
| الخطة تحدد قيم `severity` الثلاث دون معايير | معايير مكتوبة (D20) في دليل الربط ووثيقة المنتج |
| رموز 2FA الخاطئة لا تُحسب في قفل الحساب، وكل محاولة دخول فاشلة كانت تُحسب مرتين (المستمعات مسجّلة مرتين) | الرمز الخاطئ يُحسب كمحاولة فاشلة في الدخول بكلمة المرور وبالدخول الموحد، وأُلغي الاكتشاف التلقائي للمستمعات فتُحسب كل محاولة مرة واحدة |
| فلاتر السجلات حقول نصية حرة | قوائم بالقيم المستلمة فعلًا مع البحث بالكتابة |
| تجربة الأدوار تتطلب كلمة مرور ورمز 2FA لكل حساب | قائمة «حسابات تجريبية» تحت نموذج الدخول: دخول بنقرة بلا 2FA، في بيئة التطوير المحلية فقط (`DemoLogins`)، والمساران الحقيقيان (كلمة المرور والدخول الموحد) كما هما |
| الدخول بكلمة مرور صحيحة لحساب مقفل أو موقوف كان يعطي «بيانات الاعتماد غير متطابقة» فقط، فيبدو كأن كلمة المرور خطأ | رسالة واضحة (مقفل حتى كم دقيقة / موقوف) بعد كلمة المرور الصحيحة فقط، وأمر `audit:unlock-user` لمدير المنصة الوحيد |
| صفحة الملف الشخصي وخانات رمز 2FA غير مرتبة | الملف الشخصي في أقسام (البيانات الشخصية، تغيير كلمة المرور)، وخانات الرمز في كتلة متوسطة في كل مكان تظهر فيه |
| الـ ERD مزدحم | `bin/erd` يرسمه بـ Graphviz: الجداول مجمّعة حسب المجال، والخط من عمود الـ FK إلى المفتاح، ومراجع «من فعل» تُكتب في الصف بدل خطوط |

## تحديث 2026-11-17: مراجعة الكود الرابعة (`claude/code-review-2026-11-16.md`)

| الملاحظة | الإصلاح | الاختبار |
|---|---|---|
| حاوية الويب `app` في الإنتاج كانت تأخذ كلمة مرور حساب الصيانة (ALTER/DROP/TRIGGER) من `.env`، فثغرة في الويب تكفي لحذف الـ Triggers وتعديل السجلات (§4، §13) | `DB_MAINTENANCE_PASSWORD` فارغ في `app` (`docker-compose.prod.yml`)، والـ Migrations و`audit:partitions` في الإنتاج من حاوية `scheduler` (الـ Runbook) | مراجعة الملف |
| `GET /api/v1/logs` بالـ cursor كان يقرّب الوقت لأقرب ثانية، فتسقط سجلات من نفس الثانية بين الصفحات (§6) | `occurred_at` يُقرأ كـ `PreciseDateTime` يحفظ الأجزاء من الثانية في الـ cursor | `QueryTest` (5 سجلات في نفس الثانية، صفحتان) |
| سجل يُقبل (202) ثم يضيع Job تخزينه (توقف Redis أو حذف من failed_jobs) لا يكتشفه أحد، وإعادة الإرسال تُرد `duplicate` | `audit:check-ingestion` كل 15 دقيقة: مفاتيح بلا سجل بعد 30 دقيقة، ودفعات ما زالت في الطابور، وتنبيه حرج | `JobsAndAlertsTest` (اختباران) |
| قوائم الفلاتر: البحث بالكتابة يمر على كل الجدول بـ `LIKE '%…%'` | القوائم والبحث محدودة بالفترة المختارة في فلتر الفترة (أو آخر 90 يومًا) | `FilamentPagesTest` |
| اللوحة بتوقيت UTC والمستخدمون بتوقيت الرياض | العرض والفلاتر و«اليوم» بتوقيت `AUDIT_DISPLAY_TIMEZONE`، والتخزين والـ API بـ UTC (D22) | `FilamentPagesTest` |
| `backup.sh` يمرر كلمة مرور القاعدة في سطر الأوامر (تظهر في `ps`) | ملف خيارات مؤقت بصلاحية 600 (`--defaults-extra-file`) يُحذف عند الخروج | تشغيل يدوي: `make backup-full` |
| الاستعادة من الأرشيف تسرد كل ملفات المشروع، ولا تعيد مفاتيح منع التكرار | تسرد مجلدات الأشهر الممكنة فقط، وتعيد `ingestion_keys` للسجلات المسترجعة | `RetentionTest` |
| §7 «تسجيل كل محاولة فاشلة»: ما زاد على 30 في الدقيقة كان يُعدّ ولا يُحفظ | صف ملخص بالعدد لكل IP وسبب ودقيقة (D21) | `RateLimitAndHealthTest` |
| خطوط الـ ERD منحنية ومتداخلة | خطوط مستقيمة بزوايا قائمة فقط (`splines=ortho` في `bin/erd`) | `docs/erd/erd.pdf` |
| صفحة سطر تدقيق المنصة تعرض `changes` كـ JSON خام، وسطر `role.assigned` يحفظ رقم الدور بدل اسمه عند `syncRoles` (§13) | جدول «قبل / بعد» للتغييرات وقائمة «البند ← القيمة» للباقي (`PlatformAuditChanges`)، والمستمع يحوّل الأرقام لأسماء (والسطور القديمة تُحوَّل عند العرض)، وتعديل IPs بلا تغيير لا يُسجَّل | `ConfigAuditTest` (3 اختبارات جديدة) |
