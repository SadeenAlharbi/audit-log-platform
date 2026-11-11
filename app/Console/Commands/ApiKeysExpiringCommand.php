<?php

namespace App\Console\Commands;

use App\Models\ProjectApiKey;
use App\Services\Alerts\AlertNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

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
