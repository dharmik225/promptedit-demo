<?php

namespace App\Http\Requests\Generation;

use App\Enums\GenerationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::enum(GenerationStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
