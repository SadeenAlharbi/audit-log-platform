<?php

namespace App\Console\Commands;

use App\Models\PlatformAudit;
use App\Services\Audit\PlatformAuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Decision D16 keeps operational settings in .env, which is not in version
 * control. So that a change of retention, backups, alerts, API security or
 * SSO still leaves a trace (plan §13, self-audit), this records the
 * effective values in platform_audit whenever they differ from the last
 * record. Secrets are never stored, only whether they changed.
 */
#[Signature('audit:config-check')]
#[Description('Record changes of security-relevant settings (.env) in platform_audit')]
class ConfigAuditCommand extends Command
{
    /** Settings recorded with their value. */
    private const TRACKED = [
        'audit.retention', 'audit.display_timezone', 'audit.api.require_https', 'audit.api.rate_limit', 'audit.api.ip_rate_limit',
        'audit.api.max_payload', 'audit.api.signature_tolerance', 'audit.api_keys', 'audit.accounts',
        'audit.security', 'audit.alerts.mail_to', 'audit.archive', 'audit.backup',
        'audit.saml.enabled', 'audit.saml.tenant_uuid', 'audit.saml.idp.metadata_url', 'audit.saml.idp.entity_id',
        'app.env', 'app.debug', 'session.lifetime', 'session.secure',
    ];

    /** Settings recorded only as "changed" (never their value). */
    private const SECRETS = [
        'audit.alerts.slack_webhook_url', 'audit.backup.age_recipient', 'audit.saml.idp.x509_cert', 'app.key',
    ];

    public function handle(PlatformAuditLogger $audit): int
    {
        $values = [];
        foreach (self::TRACKED as $key) {
            $values[$key] = config($key);
        }
        $values = Arr::dot($values);
        foreach (self::SECRETS as $key) {
            Arr::forget($values, $key);
            $values[$key] = filled(config($key)) ? substr(hash_hmac('sha256', (string) json_encode(config($key)), 'config-audit'), 0, 12) : null;
        }
        ksort($values);
        $hash = hash('sha256', (string) json_encode($values));

        $last = PlatformAudit::query()->whereIn('action', ['config.baseline', 'config.changed'])->latest('id')->first();
        $previous = is_array($last?->changes) ? (array) ($last->changes['values'] ?? []) : [];
        if (($last?->changes['hash'] ?? null) === $hash) {
            $this->info('No change since the last record.');

            return self::SUCCESS;
        }

        $changed = $last === null ? [] : array_keys(array_diff_assoc(
            array_map(fn ($v) => json_encode($v), $values),
            array_map(fn ($v) => json_encode($v), $previous),
        ) + array_diff_key($previous, $values));

        $audit->record($last === null ? 'config.baseline' : 'config.changed', 'configuration', [
            'hash' => $hash,
            'changed' => $changed,
            'previous' => array_intersect_key($previous, array_flip($changed)),
            'values' => $values,
        ]);

        $this->info($last === null ? 'Baseline recorded.' : 'Changed: '.implode(', ', $changed));

        return self::SUCCESS;
    }
}
