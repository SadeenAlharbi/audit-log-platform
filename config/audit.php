<?php

/*
|--------------------------------------------------------------------------
| Audit Log Platform settings
|--------------------------------------------------------------------------
| See docs/decisions.md for the reasoning behind each value.
*/

return [

    // Dashboard identity (Platforms Code footer). The organisation name is
    // optional: leave it empty and the footer shows the platform name only.
    'branding' => [
        'organization' => env('AUDIT_ORGANIZATION_NAME', ''),
    ],

    // Password of the *@demo.test accounts created by `make demo` (dev only).
    'demo_password' => env('DEMO_PASSWORD', 'Demo!Passw0rd#2026'),

    'retention' => [
        // D3: platform-wide ceiling. No project may keep logs longer than this.
        // Monthly partitions that are entirely older than this are archived
        // and then dropped in one operation.
        'max_days' => (int) env('AUDIT_MAX_RETENTION_DAYS', 395),

        // Default for new projects. A project may lower it (never above max);
        // the nightly prune deletes the extra rows in small batches.
        'default_days' => (int) env('AUDIT_DEFAULT_RETENTION_DAYS', 365),

        // Rows removed per DELETE statement by the nightly prune job.
        'delete_batch_size' => (int) env('AUDIT_PRUNE_BATCH_SIZE', 5000),
    ],

    'partitions' => [
        // Months kept ready after the current month.
        'ahead' => (int) env('AUDIT_PARTITIONS_AHEAD', 3),

        // Months created before the current month when the table is first
        // created. 13 covers the whole retention window (395 days), so a
        // system that sends its history on onboarding still lands in monthly
        // partitions that retention can drop, not in p_before.
        'backfill' => (int) env('AUDIT_PARTITIONS_BACKFILL', 13),
    ],

    'storage' => [
        // InnoDB page compression for audit_logs (plan §2).
        'page_compression' => (bool) env('AUDIT_PAGE_COMPRESSION', true),
    ],

    // Connection with ALTER privileges used for partition maintenance.
    'maintenance_connection' => env('DB_MAINTENANCE_CONNECTION', 'maintenance'),

    'archiver' => [
        // MariaDB account allowed by the triggers to DELETE archived rows.
        // Must match DB_ARCHIVER_USERNAME used by the "archiver" connection.
        'db_user' => env('DB_ARCHIVER_USERNAME', 'audit_archiver'),
        'connection' => 'archiver',
    ],

    // First Super Admin, created by SuperAdminSeeder when both are set.
    'bootstrap_admin' => [
        'name' => env('ADMIN_NAME', 'Platform Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingestion API (plan §6, §7)
    |--------------------------------------------------------------------------
    */
    'api' => [
        // Max request body in bytes (1MB).
        'max_payload_bytes' => (int) env('AUDIT_API_MAX_PAYLOAD', 1024 * 1024),

        // Max logs per POST /api/v1/logs/batch.
        'max_batch_size' => (int) env('AUDIT_API_MAX_BATCH', 500),

        // Requests per minute, per API key (Redis).
        'rate_limit_per_minute' => (int) env('AUDIT_API_RATE_LIMIT', 1000),

        // Signed requests older/newer than this are rejected (replay window).
        'signature_tolerance_seconds' => (int) env('AUDIT_API_SIGNATURE_TOLERANCE', 300),

        // Reject in production when the request did not arrive over HTTPS.
        'require_https' => (bool) env('AUDIT_API_REQUIRE_HTTPS', env('APP_ENV') === 'production'),

        // occurred_at accepted window: not older than the retention ceiling
        // and not further in the future than this (clock skew).
        'max_future_skew_seconds' => (int) env('AUDIT_API_MAX_FUTURE_SKEW', 300),

        // GET /api/v1/logs: default and maximum time range when the client
        // does not send from/to (keeps queries on few partitions).
        'query_default_days' => 30,
        'query_max_days' => 400,
        'query_max_per_page' => 100,

        // Queue that stores accepted logs.
        'queue' => env('AUDIT_INGEST_QUEUE', 'ingest'),
    ],

    'api_keys' => [
        // Visible prefix of every key (first 8 chars are stored for display).
        'prefix' => 'alh_',

        // Mandatory rotation (plan §13): keys expire after this many days.
        'lifetime_days' => (int) env('AUDIT_API_KEY_LIFETIME_DAYS', 90),

        // When a key is rotated, the old one keeps working for this long so
        // the client can switch without downtime.
        'rotation_grace_hours' => (int) env('AUDIT_API_KEY_ROTATION_GRACE_HOURS', 24),

        // Owners are warned this many days before a key expires.
        'expiry_warning_days' => 14,
    ],

    /*
    |--------------------------------------------------------------------------
    | Masking (plan §7, decision D8). Matching is case-insensitive and also
    | matches keys that contain the word ("user_password", "accessToken").
    |
    | never_store: credentials and payment secrets. Replaced at ingestion,
    |   before anything is written, so they exist nowhere on the platform
    |   (not in the database, the archive or the backups). Not revealable.
    | keys: personal data masked whenever logs are shown, exported or
    |   returned by the API; revealed only to holders of logs.view_sensitive.
    |   (never_store keys are masked on display too.)
    |--------------------------------------------------------------------------
    */
    'masking' => [
        'replacement' => '********',
        'redacted' => '[REDACTED]',
        'never_store' => [
            'password', 'passwd', 'pwd', 'passphrase', 'secret', 'token', 'api_key', 'apikey',
            'authorization', 'credential', 'credentials', 'cookie', 'jwt', 'private_key', 'otp', 'pin',
            'card_number', 'cardnumber', 'credit_card', 'pan', 'cvv', 'cvc',
        ],
        'keys' => [
            'iban', 'ssn', 'national_id', 'nationalid', 'iqama', 'passport',
            'date_of_birth', 'dob',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security events (plan §7): alert when one IP is rejected repeatedly.
    |--------------------------------------------------------------------------
    */
    'security' => [
        'alert_threshold' => (int) env('AUDIT_SECURITY_ALERT_THRESHOLD', 20),
        'alert_window_minutes' => 5,
        // Events stored per IP and reason per minute; the rest are counted.
        'max_events_per_minute' => (int) env('AUDIT_SECURITY_MAX_EVENTS_PER_MINUTE', 30),
        // Requests per minute per client IP before any authentication.
        'ip_rate_limit_per_minute' => (int) env('AUDIT_API_IP_RATE_LIMIT', 3000),
        // security_events older than this are deleted by audit:prune.
        'events_retention_days' => (int) env('AUDIT_SECURITY_EVENTS_RETENTION_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard accounts (plan §13)
    |--------------------------------------------------------------------------
    */
    'accounts' => [
        'max_failed_logins' => 5,
        'lockout_minutes' => 15,
        'password_min_length' => 12,
        // Platform roles that must use 2FA (TOTP).
        'mfa_required_roles' => ['super_admin', 'security_officer'],
    ],

    /*
    |--------------------------------------------------------------------------
    | SAML single sign-on for the dashboard (decision D15)
    |--------------------------------------------------------------------------
    | Alongside e-mail and password. Only existing, active accounts may sign
    | in (matched by e-mail), and the platform's own 2FA is always asked
    | after SAML. `php artisan audit:saml-sync` writes the identity provider
    | below to the saml2_tenants table (toolkit settings: config/saml2.php).
    */
    'saml' => [
        'enabled' => (bool) env('SAML2_ENABLED', false),
        // Fixed, so the IdP can be set up with stable SP URLs:
        // /saml2/{uuid}/metadata (entity id), /saml2/{uuid}/acs, /saml2/{uuid}/sls
        'tenant_uuid' => env('SAML2_TENANT_UUID'),
        // Button label on the login page (e.g. the company's IdP name).
        'button_label' => env('SAML2_BUTTON_LABEL') ?: 'الدخول الموحد',
        // With a metadata URL, the entity id, login/logout URLs and signing
        // certificate are read from it; any value set below overrides it.
        'idp' => [
            'metadata_url' => env('SAML2_IDP_METADATA_URL'),
            'entity_id' => env('SAML2_IDP_ENTITY_ID'),
            'login_url' => env('SAML2_IDP_LOGIN_URL'),
            'logout_url' => env('SAML2_IDP_LOGOUT_URL'),
            'x509_cert' => env('SAML2_IDP_X509_CERT'),
        ],
        // The SAML attribute holding the e-mail; the NameID is used if absent.
        'email_attribute' => env('SAML2_EMAIL_ATTRIBUTE', 'email'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts (plan §11): e-mail and/or Slack incoming webhook.
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'mail_to' => array_values(array_filter(explode(',', (string) env('ALERT_MAIL_TO', '')))),
        'slack_webhook_url' => env('ALERT_SLACK_WEBHOOK_URL'),
        // Same alert is not repeated within this many minutes.
        'throttle_minutes' => 30,
        // Queue backlog that triggers an alert.
        'queue_backlog_threshold' => (int) env('ALERT_QUEUE_BACKLOG', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Archive (plan §9, §12): compressed JSONL on S3/MinIO.
    |--------------------------------------------------------------------------
    */
    'archive' => [
        'disk' => env('AUDIT_ARCHIVE_DISK', 'archive'),
        // Rows newer than this are not archived yet (lets in-flight inserts commit).
        'settle_seconds' => (int) env('AUDIT_ARCHIVE_SETTLE_SECONDS', 600), // well above the ingest job timeout (120s)
        'chunk_size' => 5000,
        // Plan §9 "soft archive": rows are deleted only once their archive
        // run is at least this many days old (they stay queryable meanwhile).
        'grace_days' => (int) env('AUDIT_ARCHIVE_GRACE_DAYS', 7),
        // Logs restored from the archive are protected from the nightly
        // prune for this many days (audit:restore-archive --hold-days).
        'restore_hold_days' => (int) env('AUDIT_RESTORE_HOLD_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups (plan §12)
    |--------------------------------------------------------------------------
    */
    'backup' => [
        // Configuration file included in the application-files backup.
        'env_file' => env('BACKUP_ENV_FILE', base_path('.env')),
        // Disk that receives encrypted backups (S3/MinIO in production).
        'disk' => env('BACKUP_DISK', 'backups'),
        // Shared volume with the backup container (status + requests).
        'spool_path' => env('BACKUP_SPOOL_PATH', storage_path('app/backup-spool')),
        // age public key used to encrypt app-file backups. The private key
        // must be kept outside the server.
        'age_recipient' => env('BACKUP_AGE_RECIPIENT'),
        // GFS retention: daily 14, weekly 8, monthly 12.
        'keep_daily' => 14,
        'keep_weekly' => 8,
        'keep_monthly' => 12,
        // Freshness checks used by backup:monitor (RPO 1h, full daily).
        'max_incremental_age_minutes' => 90,
        'max_full_age_hours' => 26,
        'max_restore_test_age_days' => 8,
        // Alert when a backup is this much smaller/bigger than the median
        // of the previous ones (0.5 = 50%).
        'size_deviation' => 0.5,
    ],

];
