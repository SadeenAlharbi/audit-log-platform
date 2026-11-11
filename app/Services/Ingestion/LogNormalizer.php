<?php

namespace App\Services\Ingestion;

use App\Models\ProjectApiKey;
use App\Services\Security\SensitiveDataMasker;
use Carbon\CarbonImmutable;

/**
 * Turns a validated payload into an audit_logs row (plain scalars and JSON
 * strings) that can be queued and inserted as-is.
 *
 * Credentials and payment secrets (config audit.masking.never_store) are
 * redacted here, before the row reaches the queue, the database, the
 * archive or a backup (decision D8).
 */
final class LogNormalizer
{
    public const DATETIME_FORMAT = 'Y-m-d H:i:s.v';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, scalar|null>
     */
    public static function toRow(array $data, ProjectApiKey $key, string $uid, CarbonImmutable $receivedAt, ?int $batchId = null): array
    {
        $redactor = SensitiveDataMasker::forStorage();

        return [
            'uid' => $uid,
            'project_id' => $key->project_id,
            'api_key_id' => $key->id,
            'batch_id' => $batchId,
            'external_id' => self::str($data['external_id'] ?? null),
            'event' => (string) $data['event'],
            'action' => (string) $data['action'],
            'severity' => $data['severity'] ?? 'info',
            'actor_type' => self::str($data['actor']['type'] ?? null),
            'actor_id' => self::str($data['actor']['id'] ?? null),
            'actor_name' => self::str($data['actor']['name'] ?? null),
            'actor_ip' => self::str($data['actor']['ip'] ?? null),
            'user_agent' => self::str($data['actor']['user_agent'] ?? null),
            'subject_type' => self::str($data['subject']['type'] ?? null),
            'subject_id' => self::str($data['subject']['id'] ?? null),
            'old_values' => self::json($redactor->mask($data['old_values'] ?? null)),
            'new_values' => self::json($redactor->mask($data['new_values'] ?? null)),
            'metadata' => self::json($redactor->mask($data['metadata'] ?? null)),
            'tags' => self::json(isset($data['tags']) ? array_values(array_unique($data['tags'])) : null),
            'request_id' => self::str($data['request_id'] ?? null),
            'correlation_id' => self::str($data['correlation_id'] ?? null),
            'occurred_at' => CarbonImmutable::parse((string) $data['occurred_at'])->utc()->format(self::DATETIME_FORMAT),
            'received_at' => $receivedAt->utc()->format(self::DATETIME_FORMAT),
        ];
    }

    private static function str(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function json(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
