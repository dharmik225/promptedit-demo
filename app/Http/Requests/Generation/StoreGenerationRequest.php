<?php

namespace App\Http\Requests\Generation;

use App\Services\Pixelforge\PixelforgePricingService;
use Illuminate\Foundation\Http\FormRequest;

class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('prompt'))) {
            $this->merge(['prompt' => trim($this->input('prompt'))]);
        }

        $referenceImageUrls = $this->input('reference_image_urls', []);

        if (! is_array($referenceImageUrls)) {
            return;
        }

        $this->merge([
            'reference_image_urls' => array_values(array_filter(
                array_map(fn (mixed $url) => is_string($url) ? trim($url) : $url, $referenceImageUrls),
                fn (mixed $url) => $url !== '' && $url !== null,
            )),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $pricing = app(PixelforgePricingService::class)->pricing();

        return [
            'prompt' => ['required', 'string', 'max:'.config('services.pixelforge.max_prompt_length')],
            ...$pricing->optionRules(),
            'reference_image_urls' => ['nullable', 'array', 'max:'.config('services.pixelforge.max_reference_images')],
            'reference_image_urls.*' => ['required', 'string', 'url:http,https', 'max:2048', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'reference_image_urls' => 'reference images',
            'reference_image_urls.*' => 'reference image URL',
        ];
    }
}
