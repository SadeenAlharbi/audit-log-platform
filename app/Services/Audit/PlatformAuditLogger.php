<?php

namespace App\Services\Audit;

use App\Models\PlatformAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Self-audit (plan §13): every change to roles, users, projects, API keys
 * and backup settings is written to the immutable platform_audit table.
 */
class PlatformAuditLogger
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(string $action, Model|string|null $target = null, array $changes = [], ?User $user = null): PlatformAudit
    {
        $user ??= Auth::guard('web')->user();
        $request = app()->runningInConsole() ? null : request();

        [$targetType, $targetId] = match (true) {
            $target instanceof Model => [$target->getMorphClass(), (string) $target->getKey()],
            is_string($target) => [$target, null],
            default => [null, null],
        };

        return PlatformAudit::query()->create([
            'user_id' => $user?->getKey(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'changes' => $changes === [] ? null : $changes,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : 'console',
        ]);
    }

    /**
     * Only the attributes that really changed, with secrets removed.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(Model $model): array
    {
        $hidden = array_merge($model->getHidden(), ['updated_at', 'created_at', 'remember_token', 'password']);
        $diff = [];

        foreach ($model->getChanges() as $key => $new) {
            if (in_array($key, $hidden, true)) {
                continue;
            }
            $diff[$key] = ['old' => $model->getOriginal($key), 'new' => $new];
        }

        return $diff;
    }
}
