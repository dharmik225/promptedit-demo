<?php

namespace App\Http\Requests\Webhooks;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Runs after VerifyPixelforgeSignature, so only authentic payloads are
 * validated. Pixelforge does not send Accept: application/json, so failures
 * are forced to JSON instead of a redirect.
 */
class PixelforgeWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'id' => ['required', 'string', 'starts_with:evt_', 'max:255'],
            'object' => ['nullable', 'string', Rule::in(['event'])],
            'type' => ['required', 'string', 'max:64'],
            'created_at' => ['nullable', 'date'],
            'data' => ['nullable', 'array'],
        ];

        if (! $this->isGenerationEvent()) {
            return $rules;
        }

        return [
            ...$rules,
            'data' => ['required', 'array'],
            'data.object' => ['required', 'array'],
            'data.object.id' => ['required', 'string', 'starts_with:gen_', 'max:255'],
            'data.object.status' => ['required', 'string', Rule::in(['queued', 'processing', 'succeeded', 'failed'])],
            'data.object.metadata' => ['nullable', 'array'],
            'data.object.rates' => ['nullable', 'array'],
            'data.object.rates.*' => ['required', 'string', 'regex:/^\d+(\.\d+)?$/'],
            'data.object.usage' => ['nullable', 'array', 'required_if:data.object.status,succeeded'],
            'data.object.usage.input_text_tokens' => ['required_with:data.object.usage', 'integer', 'min:0'],
            'data.object.usage.input_image_tokens' => ['required_with:data.object.usage', 'integer', 'min:0'],
            'data.object.usage.output_tokens' => ['required_with:data.object.usage', 'integer', 'min:0'],
            'data.object.usage.reasoning_tokens' => ['required_with:data.object.usage', 'integer', 'min:0'],
            'data.object.image_url' => ['nullable', 'string', 'url:https,http'],
            'data.object.error' => ['nullable', 'array'],
            'data.object.error.code' => ['nullable', 'string', 'max:64'],
            'data.object.error.message' => ['nullable', 'string'],
        ];
    }

    public function deliveryAttempt(): ?int
    {
        $deliveryAttempt = $this->header('Pixelforge-Delivery-Attempt');

        return is_numeric($deliveryAttempt) ? (int) $deliveryAttempt : null;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Invalid webhook payload.',
            'errors' => $validator->errors(),
        ], 422));
    }

    private function isGenerationEvent(): bool
    {
        return str_starts_with((string) $this->input('type'), 'generation.');
    }
}
