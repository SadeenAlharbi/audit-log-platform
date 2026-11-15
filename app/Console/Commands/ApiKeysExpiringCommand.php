<?php

namespace App\Console\Commands;

use App\Models\ProjectApiKey;
use App\Services\Alerts\AlertNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('api-keys:expiring')]
#[Description('Warn about API keys that expire soon (mandatory 90-day rotation)')]
class ApiKeysExpiringCommand extends Command
{
    public function handle(AlertNotifier $alerts): int
    {
        $days = (int) config('audit.api_keys.expiry_warning_days', 14);

        $keys = ProjectApiKey::query()
            ->withoutGlobalScopes()
            ->with('project:id,name,slug')
            ->whereNull('revoked_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            // A rotated key is already replaced; it is only in its grace period.
            ->whereNotExists(fn ($q) => $q->from('platform_audit')->where('action', 'api_key.rotated')
                ->where('target_type', (new ProjectApiKey)->getMorphClass())
                ->whereColumn('target_id', DB::raw('CAST(project_api_keys.id AS CHAR)')))
            ->orderBy('expires_at')
            ->get();

        foreach ($keys as $key) {
            $alerts->send(
                key: "api_key:expiring:{$key->id}",
                title: 'API key expires soon',
                message: "Key \"{$key->name}\" ({$key->key_prefix}…) of project {$key->project->name} expires {$key->expires_at->diffForHumans()}. Rotate it from the dashboard.",
                level: 'warning',
                context: ['project' => $key->project->slug, 'expires_at' => $key->expires_at->toDateString()],
            );
        }

        $this->components->info($keys->count().' key(s) expire within '.$days.' days.');

        return self::SUCCESS;
    }
}
