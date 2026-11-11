<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Ingestion\LogPayloadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authenticated and scoped by middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return LogPayloadRules::rules();
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            fn (Validator $validator) => LogPayloadRules::rejectUnknownFields($this->all(), $validator),
        ];
    }
}
