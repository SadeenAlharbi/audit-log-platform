# تقرير تمرين الاستعادة من النسخة الخارجية · 2026-11-16

**النتيجة: ناجح.** نُزّلت آخر نسخة كاملة من التخزين الخارجي، وفُك تشفيرها بالمفتاح الخاص (age)، واستُعيدت في خادم MariaDB مؤقت، واجتازت كل الفحوص (D13، الخطة §12 و§15).

| | |
|---|---|
| الأمر | `make offsite-drill` |
| البيئة | Docker Desktop على macOS، والتخزين الخارجي محاكى بـ volume منفصل (`BACKUP_RCLONE_REMOTE=/offsite`) |
| النسخة | `full-20261116T081426Z.tar.gz.age` (مشفّرة) |
| المفتاح الخاص | `secrets/backup-age-identity.txt`، مركّب للتمرين فقط |
| مدة الاستعادة | ثانيتان (810 سجلات تجريبية؛ الهدف RTO ساعتان) |
| الجداول (CHECK TABLE) | 19، بدون أخطاء |
| سجلات `audit_logs` | 810، وأعلى `id` = 810 (المتوقع ≥ 810) |
| مطابقة العدد مع القاعدة الحية | المستعاد 810، الحي 810 |
| أقسام `audit_logs` | 19 |

**ملاحظة ظهرت أثناء التمرين وأُصلحت:** التحقق من النسخة الخارجية بعد الرفع كان يستخدم `rclone check`، الذي يقارن مجلدات لا ملفات، فسجّل كل نسخة «غير مطابقة» رغم سلامتها. صار يقارن SHA-256 للنسخة المنزّلة بالنسخة المحلية.

**في الإنتاج:** يُعاد التمرين كل 3 أشهر على التخزين الخارجي الحقيقي (Runbook §3.5)، ويُرفق تقريره هنا.

## التقرير كما أصدرته المنصة

| | |
|---|---|
| Date (UTC) | 2026-11-16T08:14:28Z |
| Source | off-site copy full-20261116T081426Z.tar.gz.age (downloaded, decrypted with the private key) |
| Result | **succeeded** |
| Restore chain | full |
| Restore time (RTO evidence) | 2s |
| Tables checked (CHECK TABLE) | 19 — failures: none |
| audit_logs rows | 810 |
| audit_logs max(id) | 810 (expected ≥ 810) |
| Rows with id ≤ 810 | restored 810, live 810 (restored must be ≥ live) |
| audit_logs partitions | 19 |
