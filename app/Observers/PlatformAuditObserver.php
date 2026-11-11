<?php

namespace App\Observers;

use App\Models\ProjectApiKey;
use App\Services\Audit\PlatformAuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes create/update events of administrative models to platform_audit.
 * API key create/rotate/revoke are recorded with more context by
 * ApiKeyManager, so plain updates of keys are only recorded here when they
 * are not one of those operations.
 */
class PlatformAuditObserver
{
    public function __construct(private PlatformAuditLogger $audit) {}

    public function created(Model $model): void
    {
        if ($model instanceof ProjectApiKey) {
            return; // recorded by ApiKeyManager
        }

        $this->audit->record(self::name($model).'.created', $model, $this->attributes($model));
    }

    public function updated(Model $model): void
    {
        $diff = PlatformAuditLogger::diff($model);
        unset($diff['last_used_at'], $diff['last_used_ip'], $diff['last_login_at'], $diff['last_login_ip'], $diff['failed_login_attempts'], $diff['locked_until']);

        if ($diff === []) {
            return;
        }

        if ($model instanceof ProjectApiKey && (isset($diff['revoked_at']) || isset($diff['expires_at']) || isset($diff['allowed_ips']))) {
            return; // recorded by ApiKeyManager
        }

        $this->audit->record(self::name($model).'.updated', $model, $diff);
    }

    public function deleted(Model $model): void
    {
        $this->audit->record(self::name($model).'.deleted', $model);
    }

    private static function name(Model $model): string
    {
        return Str::snake(class_basename($model));
    }

    /** @return array<string, mixed> */
    private function attributes(Model $model): array
    {
        return collect($model->attributesToArray())
            ->except(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes', 'created_at', 'updated_at'])
            ->all();
    }
}
