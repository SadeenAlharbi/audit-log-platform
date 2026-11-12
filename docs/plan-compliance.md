# مطابقة الخطة · Plan Compliance (v1.1)

كل متطلب في «وثيقة متطلبات وخطة تطوير منصة Audit Log المركزية» (الإصدار 1.1)، وأين نُفّذ، وكيف يُثبت. ✓ = منفّذ ومختبر، ◐ = منفّذ مع ملاحظة.

## القيود الإلزامية

| المتطلب | الحالة | الدليل |
|---|---|---|
| Laravel 13 + PHP 8.4 فقط | ✓ | `composer.json` (`"php": "^8.4"`، `"laravel/framework": "^13.0"`)، `Dockerfile` (`php:8.4-fpm`). الـ CI يفشل إن تغيّرت |
| Node 24+ | ✓ | `package.json` engines، `.nvmrc`، `node:24-alpine` |
| Filament 5 | ✓ | `filament/filament ^5.0` |
| Migrations نظيفة ونهائية | ✓ | 17 Migration، واحد لكل جدول، ولم يتغير أي منها منذ تجميد المخطط |

## §3 Docker

| المتطلب | الحالة | الدليل |
|---|---|---|
| الخدمات: app، nginx، mariadb، redis، queue-worker، scheduler، backup | ✓ | `docker-compose.yml` (التخزين الخارجي S3 في الإنتاج، ومحلي في التطوير) |
| Dockerfile متعدد المراحل dev/prod، الإضافات pdo_mysql/redis/intl/bcmath/zip/opcache، وأصول بـ node:24-alpine | ✓ | `Dockerfile` (assets / base / dev / prod / web) |
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
| `schema.sql` أو ERD | ✓ | `database/schema/schema.sql` + ERD في `docs/schema.md` |
| InnoDB page compression وأعمدة JSON | ✓ | `PAGE_COMPRESSED=1` على `audit_logs` |

## §5 الربط

| المتطلب | الحالة | الدليل |
|---|---|---|
| الـ API هو العقد، وموثّق في OpenAPI | ✓ | `docs/openapi.yaml`، `/docs/api` |
| حزمة `company/audit-client`: ريبو مستقل، PHP 8.2+، Laravel 11/12/13 | ✓ | ريبو `audit-client` (v1.0.0) |
| إعدادات endpoint/project/key/secret/queue/timeout/retry/enabled | ✓ | `config/audit-client.php` |
| إرسال غير متزامن بطابور + Exponential backoff | ✓ | `Jobs/SendAuditLogs`، واختبارات الحزمة |
| تجميع إلى `/logs/batch` | ✓ | `Support/Buffer` |
| Facade، Trait `Auditable`، Listener للمصادقة، Middleware لـ request_id | ✓ | 68 اختبارًا في الحزمة |
| توقيع HMAC تلقائي وإخفاء الحقول | ✓ | `Security/Signer`، `Security/Masker`. التوقيع مطابق لتوقيع المنصة: 200 من 200 مدخلًا عشوائيًا، واختبار end-to-end حقيقي |
| outbox + `audit:flush` | ✓ | `DatabaseOutbox` / `FileOutbox`، `FlushCommand` |
| نشر بمستودع Composer خاص + SemVer + CHANGELOG | ✓ | `docs/publishing.md`، `docs/satis.json`، `CHANGELOG.md`، tag `v1.0.0` |
| اختبارات بـ `Http::fake()` | ✓ | `tests/` في الحزمة |
| أمثلة cURL / PHP / JS / Python | ✓ | `docs/integration-guide.md` وصفحة المشروع في اللوحة |
| Onboarding: slug ومفتاح تلقائي، يُعرض مرة واحدة، hash، أكثر من مفتاح، تدوير وإلغاء، صفحة دليل الربط، زر سجل تجريبي | ✓ | `FilamentPagesTest`، `FilamentActionsTest` |

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
| تسجيل المحاولات الفاشلة + تنبيه عند التكرار | ✓ | `SecurityOpsTest` |

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
| استعادة سجلات مشروع واحد | ✓ | `audit:restore-archive`، وزر في اللوحة. تقرير الاستعادة §5 |

## §10 الجودة

| المتطلب | الحالة | الدليل |
|---|---|---|
| Pest للـ API: مصادقة، توقيع، rate limit، idempotency، batch | ✓ | `tests/Feature/Api` |
| اختبارات سياسات الوصول في Filament | ✓ | `tests/Feature/Dashboard` |
| Pint + PHPStan مستوى 6 | ✓ | بدون أخطاء |
| CI داخل Docker | ✓ | `.github/workflows/ci.yml` |
| تغطية 80%+ لطبقة الـ API | ✓ | `bin/coverage-api`: 95.3% (والمشروع كله 90.3%) |

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
| أرشيف JSONL شهري مستقل + Write-Ahead Archive | ◐ | الأرشيف المتزايد كل ساعة يغطي الشهري ويزيد عليه، فلا حاجة لتصدير شهري منفصل |
| RPO ساعة / RTO ساعتان | ✓ | تقرير الاستعادة (PITR مطابق، والاستعادة 7 ثوانٍ لهذا الحجم) |
| mariadb-backup في حاوية مستقلة، log_bin مع expire_logs_days | ✓ | `docker/backup`، `audit.cnf` |
| spatie/laravel-backup لملفات التطبيق | ◐ | بديل مكافئ مشفّر بـ age وبنفس أسماء الأوامر (D7) |
| تشفير قبل الرفع (age)، والمفتاح خارج الخادم | ✓ | `bin/setup` + Runbook §1.3 |
| `backup:run` / `clean` / `monitor` + سياسة 14/8/12 | ✓ | `BackupTest` |
| `backup:restore-test` أسبوعيًا مع Checksum | ✓ | `backup.sh restore-test`، تقرير الاستعادة |
| تنبيه عند فشل النسخ أو الحجم غير الطبيعي أو فشل الاختبار | ✓ | `backup:monitor` |
| Runbook للاستعادة الكاملة والجزئية | ✓ | `docs/runbook.md` |
| `backup_runs` في Filament | ✓ | صفحة «النسخ الاحتياطي» |

## §13 الصلاحيات

| المتطلب | الحالة | الدليل |
|---|---|---|
| RBAC بـ spatie، مستويان، الأدوار الخمسة، الصلاحيات التفصيلية | ✓ | `docs/permissions-matrix.md`، `RoleMatrixTest` |
| Policies لكل Model في Filament والـ API | ✓ | `app/Policies` |
| Global Scope على AuditLog وProject | ✓ | `ProjectAccessScope` (ويشمل ProjectApiKey) |
| scopes للمفاتيح ومنع الجمع إلا بقرار صريح | ✓ | `FilamentPagesTest` |
| منع التعديل في الـ Policy والـ Trigger | ✓ | `RoleMatrixTest`، `AppendOnlyTest` |
| 2FA إلزامي لـ Super Admin وSecurity Officer | ✓ | `FilamentPagesTest` |
| SSO (SAML/OIDC) «إن وُجد» | ◐ | غير مفعّل: لم يُحدَّد مزوّد هوية في الشركة. Filament يدعم إضافته لاحقًا دون تغيير المخطط |
| انتهاء الجلسة، قفل الحساب، كلمات مرور قوية، تدوير المفاتيح كل 90 يومًا | ✓ | `SESSION_LIFETIME=30`، `SecurityOpsTest` |
| Self-Audit في platform_audit | ✓ | `PlatformAuditObserver`، `RecordPermissionChanges` |
| تقرير دوري بالصلاحيات الحساسة + Access Review كل 3 أشهر | ✓ | `access:review` |
| اختبارات: كل دور يرى ما يُسمح له فقط، مع حالات سلبية | ✓ | `RoleMatrixTest` |

## §15 المخرجات

| المخرج | المكان |
|---|---|
| الريبو مع Docker بأمر واحد | `./bin/setup` |
| OpenAPI + README | `docs/openapi.yaml`، `README.md`، `docs/integration-guide.md` |
| حزمة audit-client بريبو مستقل | `audit-client/` (v1.0.0) |
| schema.sql/ERD + Migrations نظيفة | `database/schema/schema.sql`، `docs/schema.md` |
| Runbook | `docs/runbook.md` |
| مصفوفة الأدوار موثقة ومختبرة | `docs/permissions-matrix.md` + `RoleMatrixTest` |
| تغطية 80%+ للـ API | `bin/coverage-api` (95.3%) |
| تقرير اختبار استعادة ناجح | `docs/reports/restore-test-2026-11-11.md` |
