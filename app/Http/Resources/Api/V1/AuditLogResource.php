<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AuditLog;
use App\Services\Security\SensitiveDataMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of a log. Sensitive values are always masked for
 * API clients; the internal BIGINT id is never exposed.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $masker = app(SensitiveDataMasker::class);

        return [
            'uid' => $this->uid,
            'external_id' => $this->external_id,
            'event' => $this->event,
            'action' => $this->action,
            'severity' => $this->severity,
            'actor' => [
                'type' => $this->actor_type,
                'id' => $this->actor_id,
                'name' => $this->actor_name,
                'ip' => $this->actor_ip,
                'user_agent' => $this->user_agent,
            ],
            'subject' => [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
            ],
            'old_values' => $masker->mask($this->old_values),
            'new_values' => $masker->mask($this->new_values),
            'metadata' => $masker->mask($this->metadata),
            'tags' => $this->tags ?? [],
            'request_id' => $this->request_id,
            'correlation_id' => $this->correlation_id,
            'occurred_at' => $this->occurred_at->format('Y-m-d\TH:i:s.v\Z'),
            'received_at' => $this->received_at->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
