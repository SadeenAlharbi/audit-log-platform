<?php

namespace App\Filament\Support;

use Illuminate\Support\Str;

/**
 * Arabic names for the codes the dashboard shows (roles, permissions,
 * rejection reasons, admin actions, record types). The codes themselves stay
 * unchanged in the database, the API and exports; an unknown code is shown
 * as it is.
 */
final class Labels
{
    /** @var array<string, string> */
    public const ROLES = [
        'super_admin' => 'مدير المنصة',
        'platform_auditor' => 'مدقق المنصة',
        'security_officer' => 'مسؤول الأمن',
        'project_owner' => 'مالك المشروع',
        'project_viewer' => 'مشاهد المشروع',
    ];

    /** @var array<string, string> */
    public const PERMISSION_GROUPS = [
        'logs' => 'السجلات',
        'projects' => 'المشاريع',
        'api_keys' => 'مفاتيح API',
        'users' => 'المستخدمون',
        'roles' => 'الأدوار',
        'backups' => 'النسخ الاحتياطي',
        'security_events' => 'الأمان',
        'settings' => 'التشغيل',
    ];

    /** @var array<string, string> */
    public const PERMISSIONS = [
        'logs.view' => 'عرض السجلات',
        'logs.export' => 'تصدير السجلات',
        'logs.view_sensitive' => 'كشف البيانات الحساسة',
        'projects.view' => 'عرض المشاريع',
        'projects.create' => 'إنشاء مشروع',
        'projects.update' => 'تعديل المشروع',
        'projects.archive' => 'أرشفة المشروع',
        'api_keys.view' => 'عرض المفاتيح',
        'api_keys.create' => 'إنشاء مفتاح',
        'api_keys.rotate' => 'تدوير مفتاح',
        'api_keys.revoke' => 'إلغاء مفتاح',
        'api_keys.manage_ips' => 'تقييد عناوين IP',
        'users.manage' => 'إدارة المستخدمين',
        'roles.manage' => 'إدارة الأدوار',
        'backups.view' => 'عرض النسخ',
        'backups.run' => 'تشغيل نسخة',
        'backups.restore' => 'طلب استعادة',
        'security_events.view' => 'عرض المحاولات المرفوضة',
        'settings.manage' => 'صفحات التشغيل (Horizon، تدقيق المنصة)',
    ];

    /** @var array<string, string> */
    public const SECURITY_REASONS = [
        'missing_credentials' => 'بدون مفتاح',
        'invalid_key' => 'مفتاح غير صحيح',
        'project_mismatch' => 'المفتاح لمشروع آخر',
        'key_revoked' => 'مفتاح ملغى',
        'key_expired' => 'مفتاح منتهي',
        'project_inactive' => 'المشروع موقوف',
        'ip_not_allowed' => 'عنوان IP غير مسموح',
        'scope_denied' => 'خارج صلاحية المفتاح',
        'missing_signature' => 'بدون توقيع',
        'invalid_signature' => 'توقيع غير صحيح',
        'stale_timestamp' => 'وقت الطلب قديم',
        'replayed_request' => 'طلب مكرر (إعادة إرسال)',
        'rate_limited' => 'تجاوز حد المفتاح',
        'ip_rate_limited' => 'تجاوز حد العنوان',
        'https_required' => 'بدون HTTPS',
        'payload_too_large' => 'حجم أكبر من المسموح',
        'unsupported_media_type' => 'ليس JSON',
    ];

    /** @var array<string, string> */
    public const SECURITY_DETAILS = [
        'method' => 'الطريقة',
        'path' => 'المسار',
        'user_agent' => 'المتصفح أو الأداة',
        'project_header' => 'المشروع المرسل',
        'key_prefix' => 'بادئة المفتاح',
        'bytes' => 'الحجم (بايت)',
        'request_id' => 'رقم الطلب',
        'count' => 'العدد',
        // Summary row of attempts over the per-minute sample (D21).
        'suppressed' => 'عدد المحاولات في هذه الدقيقة',
        'window_start' => 'بداية الدقيقة',
        'note' => 'ملاحظة',
    ];

    /**
     * Field names in platform_audit details (the platform's own columns and
     * record() payloads). Audit logs of other systems keep their own keys.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'id' => 'المعرّف', 'name' => 'الاسم', 'email' => 'البريد', 'is_active' => 'نشط',
        'password_changed_at' => 'تغيير كلمة المرور', 'email_verified_at' => 'تأكيد البريد',
        'slug' => 'المعرّف (X-Project)', 'description' => 'الوصف', 'retention_days' => 'مدة الاحتفاظ (يوم)',
        'archived_at' => 'وقت الأرشفة', 'archived_by' => 'أرشفه', 'created_by' => 'أنشأه',
        'project' => 'المشروع', 'project_id' => 'المشروع', 'user_id' => 'المستخدم', 'role' => 'الدور',
        'environment' => 'البيئة', 'scopes' => 'الصلاحية', 'signed' => 'موقّع', 'allowed_ips' => 'عناوين IP المسموحة',
        'expires_at' => 'ينتهي', 'revoked_at' => 'وقت الإلغاء', 'revoked_by' => 'ألغاه', 'key_prefix' => 'بادئة المفتاح',
        'replaced_by' => 'المفتاح البديل', 'old_key_expires_at' => 'انتهاء المفتاح القديم',
        'reason' => 'السبب', 'method' => 'الطريقة', 'via' => 'عن طريق', 'ip' => 'عنوان IP', 'locked' => 'مقفل',
        'attempts' => 'المحاولات', 'format' => 'الصيغة', 'rows' => 'عدد الصفوف', 'truncated' => 'مقتطع',
        'file' => 'الملف', 'sensitive_only' => 'الحساسة فقط', 'mailed_to' => 'أُرسل إلى', 'deleted' => 'المحذوف',
        'type' => 'النوع', 'status' => 'الحالة', 'stage' => 'المرحلة', 'stored' => 'المخزَّن', 'uid' => 'رقم السجل',
        'http_status' => 'رمز الاستجابة', 'idp' => 'مزوّد الهوية', 'uuid' => 'المعرّف', 'watermark' => 'آخر ما أُرشف',
        'filters' => 'التصفيات', 'items' => 'العناصر', 'identifier_hash' => 'بصمة المعرّف', 'value' => 'القيمة',
    ];

    public static function field(string $key): string
    {
        return self::FIELDS[$key] ?? $key;
    }

    /** @var array<string, string> */
    public const ACTIONS = [
        'auth.login' => 'تسجيل دخول',
        'auth.logout' => 'تسجيل خروج',
        'auth.login_failed' => 'محاولة دخول فاشلة',
        'auth.account_locked' => 'قفل حساب',
        'role.assigned' => 'منح دور',
        'role.removed' => 'سحب دور',
        'permission.granted' => 'منح صلاحيات',
        'permission.revoked' => 'سحب صلاحيات',
        'user.mfa_reset' => 'تصفير المصادقة الثنائية',
        'user.unlocked' => 'فك قفل حساب',
        'api_key.created' => 'إنشاء مفتاح',
        'api_key.rotated' => 'تدوير مفتاح',
        'api_key.revoked' => 'إلغاء مفتاح',
        'api_key.allowed_ips_updated' => 'تعديل عناوين IP لمفتاح',
        'project.member_added' => 'إضافة عضو لمشروع',
        'project.member_removed' => 'إزالة عضو من مشروع',
        'project.member_role_changed' => 'تغيير دور عضو',
        'project.test_log_sent' => 'إرسال سجل تجريبي',
        'logs.exported' => 'تصدير سجلات',
        'access.review_generated' => 'إنشاء مراجعة الصلاحيات',
        'access.review_exported' => 'تصدير مراجعة الصلاحيات',
        'audit.pruned' => 'حذف حسب مدة الاحتفاظ',
        'backup.requested' => 'طلب نسخة احتياطية',
        'backup.cleaned' => 'تنظيف النسخ القديمة',
        'backup.project_restore_requested' => 'طلب استعادة مشروع',
        'backup.restore_recorded' => 'تسجيل استعادة',
        'sso.configured' => 'إعداد الدخول الموحد (SAML)',
        'config.baseline' => 'تسجيل الإعدادات الأولى',
        'config.changed' => 'تغيير في الإعدادات',
    ];

    /** @var array<string, string> */
    public const RECORD_TYPES = [
        'project' => 'مشروع',
        'project_api_key' => 'مفتاح API',
        'user' => 'مستخدم',
        'role' => 'دور',
        'backup_run' => 'عملية نسخ',
        'audit_log' => 'سجل',
        // target_type values that are not models (PlatformAuditLogger::record with a string).
        'configuration' => 'الإعدادات',
        'users' => 'المستخدمون',
        'backup' => 'النسخ الاحتياطي',
        'audit_logs' => 'سجلات التدقيق',
        'unknown_user' => 'مستخدم غير معروف',
    ];

    /** @var array<string, string> */
    private const VERBS = ['created' => 'إنشاء', 'updated' => 'تعديل', 'deleted' => 'حذف'];

    public static function role(?string $name): string
    {
        return self::ROLES[$name] ?? (string) $name;
    }

    public static function permission(string $name): string
    {
        return self::PERMISSIONS[$name] ?? $name;
    }

    public static function reason(?string $code): string
    {
        return self::SECURITY_REASONS[$code] ?? (string) $code;
    }

    /** "project.updated" → "تعديل مشروع"; exact names first. */
    public static function action(?string $action): string
    {
        $action = (string) $action;
        if (isset(self::ACTIONS[$action])) {
            return self::ACTIONS[$action];
        }

        [$entity, $verb] = array_pad(explode('.', $action, 2), 2, '');

        return isset(self::VERBS[$verb])
            ? self::VERBS[$verb].' '.(self::RECORD_TYPES[$entity] ?? $entity)
            : $action;
    }

    /** "App\Models\ProjectApiKey" → "مفتاح API". */
    public static function recordType(?string $class): string
    {
        $key = Str::snake(class_basename((string) $class));

        return self::RECORD_TYPES[$key] ?? class_basename((string) $class);
    }
}
