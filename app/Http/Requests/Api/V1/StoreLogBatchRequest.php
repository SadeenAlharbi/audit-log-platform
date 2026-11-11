<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates the envelope only. Each item is validated separately so one bad
 * item does not reject the whole batch (see LogIngestController::batch).
 */
class StoreLogBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $max = (int) config('audit.api.max_batch_size', 500);

        return [
            'logs' => ['required', 'array', 'list', 'min:1', "max:{$max}"],
            'logs.*' => ['array'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach (array_keys($this->all()) as $key) {
                    if ($key !== 'logs') {
                        $validator->errors()->add($key, "The field [{$key}] is not allowed.");
                    }
                }
            },
        ];
    }
}
