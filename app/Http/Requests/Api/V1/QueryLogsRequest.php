<?php

namespace App\Http\Requests\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class QueryLogsRequest extends FormRequest
{
    public const FILTERS = [
        'event', 'action', 'severity', 'actor_type', 'actor_id', 'subject_type', 'subject_id',
        'tag', 'correlation_id', 'request_id', 'external_id', 'from', 'to', 'per_page', 'cursor',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $max = (int) config('audit.api.query_max_per_page', 100);

        return [
            'event' => ['nullable', 'string', 'max:191'],
            'action' => ['nullable', 'string', 'max:32'],
            'severity' => ['nullable', 'string', 'regex:/^(info|warning|critical)(,(info|warning|critical))*$/'],
            'actor_type' => ['nullable', 'string', 'max:191'],
            'actor_id' => ['nullable', 'string', 'max:191'],
            'subject_type' => ['nullable', 'string', 'max:191'],
            'subject_id' => ['nullable', 'string', 'max:191'],
            'tag' => ['nullable', 'string', 'max:50'],
            'correlation_id' => ['nullable', 'string', 'max:64'],
            'request_id' => ['nullable', 'string', 'max:64'],
            'external_id' => ['nullable', 'string', 'max:191'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', "max:{$max}"],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach (array_keys($this->query()) as $key) {
                    if (! in_array($key, self::FILTERS, true)) {
                        $validator->errors()->add((string) $key, "The filter [{$key}] is not supported.");
                    }
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                [$from, $to] = $this->range();
                $maxDays = (int) config('audit.api.query_max_days', 400);

                if ($from->greaterThan($to)) {
                    $validator->errors()->add('from', 'The from date must be before the to date.');
                } elseif ($from->diffInDays($to) > $maxDays) {
                    $validator->errors()->add('from', "The range must not exceed {$maxDays} days.");
                }
            },
        ];
    }

    /**
     * The occurred_at range; defaults to the last N days so every query can
     * be pruned to a few partitions.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $to = $this->filled('to')
            ? CarbonImmutable::parse((string) $this->query('to'))->utc()
            : CarbonImmutable::now('UTC')->addSeconds((int) config('audit.api.max_future_skew_seconds', 300));
        $from = $this->filled('from')
            ? CarbonImmutable::parse((string) $this->query('from'))->utc()
            : $to->subDays((int) config('audit.api.query_default_days', 30));

        return [$from, $to];
    }
}
