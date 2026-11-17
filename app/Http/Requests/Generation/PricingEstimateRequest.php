<?php

namespace App\Http\Requests\Generation;

use App\Services\Pixelforge\PixelforgePricingService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Takes the prompt's character count instead of the prompt itself, so
 * prompts never end up in query strings or access logs.
 */
class PricingEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $pricing = app(PixelforgePricingService::class)->pricing();

        return [
            'prompt_characters' => ['required', 'integer', 'min:0', 'max:'.config('services.pixelforge.max_prompt_length')],
            ...$pricing->optionRules(),
            'reference_image_count' => ['required', 'integer', 'min:0', 'max:'.config('services.pixelforge.max_reference_images')],
        ];
    }
}
