# مصفوفة الأدوار والصلاحيات (Plan §13)

المصدر الوحيد للأدوار والصلاحيات هو `database/seeders/RolesAndPermissionsSeeder.php`، وهو idempotent. المصفوفة نفسها **مختبرة آليًا** في `tests/Feature/Dashboard/RoleMatrixTest.php`: أي تغيير في الصلاحيات يُفشل الاختبار حتى تُحدَّث هذه الوثيقة والاختبار معًا.

## مستويا الصلاحيات

| المستوى | أين يُمنح | الأدوار |
|---|---|---|
| **المنصة** | `model_has_roles` (spatie/laravel-permission) | Super Admin، Platform Auditor، Security Officer |
| **المشروع** | `project_user` (المستخدم + المشروع + الدور) | Project Owner، Project Viewer |

صلاحية المستخدم على مشروع = صلاحياته على مستوى المنصة **أو** صلاحيات دوره في ذلك المشروع. المستخدم المعطَّل، أو المشروع المؤرشف، يعني عدم الوصول.

## الصلاحيات حسب الدور

| الصلاحية | Super Admin | Platform Auditor | Security Officer | Project Owner | Project Viewer |
|---|:-:|:-:|:-:|:-:|:-:|
| `projects.view` | ✓ | ✓ | ✓ | ✓ (مشروعه) | ✓ (مشروعه) |
| `projects.create` | ✓ | | | | |
| `projects.update` | ✓ | | | ✓ (مشروعه) | |
| `projects.archive` | ✓ | | | | |
| `api_keys.view` | ✓ | | ✓ | ✓ (مشروعه) | |
| `api_keys.create` / `rotate` / `revoke` | ✓ | | | ✓ (مشروعه) | |
| `api_keys.manage_ips` | ✓ | | ✓ | ✓ (مشروعه) | |
| `logs.view` | ✓ | ✓ (كل المشاريع) | | ✓ (مشروعه) | ✓ (مشروعه) |
| `logs.export` | ✓ | ✓ | | ✓ (مشروعه) | ✓ (مشروعه) |
| `logs.view_sensitive` | ✓ | | | | |
| `users.manage` | ✓ | | | | |
| `roles.manage` | ✓ | | | | |
| `backups.view` / `run` / `restore` | ✓ | | | | |
| `security_events.view` | ✓ | | ✓ | | |
| `settings.manage` | ✓ | | | | |
| إضافة أعضاء لمشروعه | ✓ | | | ✓ | |

`logs.view_sensitive` تُمنح لعدد محدود جدًا. الطريقة: أنشئ من «الأدوار والصلاحيات» دورًا مخصصًا على مستوى المنصة (مثل «مراجع البيانات الحساسة»)، ثم أسنده للمستخدم المعني. يُسجَّل كل منح أو سحب في «تدقيق المنصة»، ويظهر صاحب الصلاحية في تقرير المراجعة الشهري.

## قواعد لا يغيّرها أي دور

| القاعدة | أين تُفرض |
|---|---|
| لا أحد يعدّل أو يحذف سجل تدقيق | Policy (`AuditLogPolicy`) + Trigger في MariaDB + غياب أي مسار API للتعديل (405) |
| لا أحد يحذف مشروعًا، بل يؤرشف | Policy + Trigger على `projects` |
| لا أحد يحذف مفتاح API، بل يلغيه | Policy |
| `platform_audit` لا يُعدَّل ولا يُحذف | Trigger + Model guard |
| كل مستخدم يرى فقط مشاريعه | Global Scope (`ProjectAccessScope`) على `AuditLog` و`Project` و`ProjectApiKey`، في اللوحة والتصدير والأوامر |
| مفتاح API يرى مشروعه فقط | Middleware `AuthenticateApiKey` + فلترة `project_id` في كل استعلام |
| المصادقة الثنائية (TOTP) إلزامية | Super Admin و Security Officer (`RequireMfaForPrivilegedRoles`) |

## ما تثبته الاختبارات

ملف `tests/Feature/Dashboard/RoleMatrixTest.php` يثبت:

- **مصفوفة القدرات:** 17 قدرة لكل دور من الخمسة، بما فيها الحالات السلبية الصريحة:
  - Viewer لا يرى مشروعًا آخر.
  - Auditor لا يرى البيانات الحساسة.
  - Security Officer لا يرى السجلات.
- **منع التعديل:** لا أحد يستطيع إنشاء سجل أو تعديله أو حذفه، ولا حذف مشروع أو مفتاح.
- **الـ Global Scope:** يقيّد الاستعلامات تلقائيًا.
- **المستخدم المعطَّل والمشروع المؤرشف:** يفقدان الوصول فورًا.

وملفات أخرى تكمل التغطية:

| الملف | ما يثبته |
|---|---|
| `tests/Feature/Dashboard/FilamentPagesTest.php` | تحقق الصلاحيات في صفحات Filament نفسها، وأن التصدير يحترم الـ Scope والإخفاء |
| `tests/Feature/Api/QueryTest.php` | تحقق الـ Scope في الـ API |

## مراجعة الصلاحيات (Access Review)

| الأمر | التوقيت | ما يفعله |
|---|---|---|
| `php artisan access:review --sensitive-only` | شهريًا | تقرير بمن يملك صلاحية حساسة، يُرسَل للـ Super Admin |
| `php artisan access:review` | كل 3 أشهر | تصدير كامل (CSV) لكل المستخدمين وأدوارهم وصلاحياتهم ومشاريعهم، في `storage/app/private/access-reviews/` ويُرسَل بالبريد |
