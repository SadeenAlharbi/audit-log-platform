<?php

namespace App\Services\Ingestion;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * The contract of one audit log (OpenAPI schema "LogInput").
 * Used for POST /logs and for every item of POST /logs/batch.
 */
final class LogPayloadRules
{
    /** Top-level fields and the allowed keys of nested objects. */
    public const FIELDS = [
        'external_id' => null,
        'event' => null,
        'action' => null,
        'severity' => null,
        'occurred_at' => null,
        'actor' => ['type', 'id', 'name', 'ip', 'user_agent'],
        'subject' => ['type', 'id'],
        'old_values' => null,
        'new_values' => null,
        'metadata' => null,
        'tags' => null,
        'request_id' => null,
        'correlation_id' => null,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'external_id' => ['nullable', 'string', 'max:191'],
            'event' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:\-\/]*$/'],
            'action' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_.\-]*$/'],
            'severity' => ['nullable', 'string', 'in:info,warning,critical'],
            'occurred_at' => ['required', 'date', self::occurredAtWindow()],

            'actor' => ['nullable', 'array'],
            'actor.type' => ['nullable', 'string', 'max:191'],
            'actor.id' => ['nullable', 'max:191', self::scalar()],
            'actor.name' => ['nullable', 'string', 'max:255'],
            'actor.ip' => ['nullable', 'ip'],
            'actor.user_agent' => ['nullable', 'string', 'max:512'],

            'subject' => ['nullable', 'array'],
            'subject.type' => ['nullable', 'string', 'max:191'],
            'subject.id' => ['nullable', 'max:191', self::scalar()],

            'old_values' => ['nullable', 'array'],
            'new_values' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],

            'request_id' => ['nullable', 'string', 'max:64'],
            'correlation_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * Validate one item; returns a Validator so callers can read errors.
     *
     * @param  array<mixed>  $data
     */
    public static function validator(array $data): ValidatorInstance
    {
        $validator = Validator::make($data, self::rules());
        $validator->after(fn (ValidatorInstance $v) => self::rejectUnknownFields($data, $v));

        return $validator;
    }

    /**
     * Plan §7: unknown fields are rejected, not silently dropped.
     *
     * @param  array<mixed>  $data
     */
    public static function rejectUnknownFields(array $data, ValidatorInstance $validator, string $prefix = ''): void
    {
        foreach ($data as $key => $value) {
            if (! array_key_exists($key, self::FIELDS)) {
                $validator->errors()->add($prefix.$key, "The field [{$key}] is not allowed.");

                continue;
            }

            $nested = self::FIELDS[$key];
            if ($nested !== null && is_array($value)) {
                foreach (array_keys($value) as $child) {
                    if (! in_array($child, $nested, true)) {
                        $validator->errors()->add("{$prefix}{$key}.{$child}", "The field [{$key}.{$child}] is not allowed.");
                    }
                }
            }
        }
    }

    private static function occurredAtWindow(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                $at = CarbonImmutable::parse((string) $value)->utc();
            } catch (\Throwable) {
                return; // the "date" rule reports it
            }

            $oldest = CarbonImmutable::now('UTC')->subDays((int) config('audit.retention.max_days', 395));
            $latest = CarbonImmutable::now('UTC')->addSeconds((int) config('audit.api.max_future_skew_seconds', 300));

            if ($at->lessThan($oldest)) {
                $fail('The occurred_at is older than the retention period.');
            } elseif ($at->greaterThan($latest)) {
                $fail('The occurred_at is in the future.');
            }
        };
    }

    private static function scalar(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && ! is_string($value) && ! is_int($value)) {
                $fail("The {$attribute} must be a string or an integer.");
            }
        };
    }
}
