<?php

namespace App\Http\Resources\Generation;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class Resource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'user' => $this->whenLoaded('user', fn () => [
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'provider' => $this->provider,
            'provider_generation_id' => $this->provider_generation_id,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_image_urls' => $this->reference_image_urls,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_in_progress' => $this->status->isInProgress(),
            'image_url' => $this->displayImageUrl(),
            'error' => $this->error_code ? [
                'code' => $this->error_code,
                'message' => $this->error_message,
            ] : null,
            'markup_percentage' => $this->markup_percentage,
            'rates' => $this->rates,
            'tokens' => [
                'estimated' => [
                    'input_text_tokens' => $this->estimated_input_text_tokens,
                    'input_image_tokens' => $this->estimated_input_image_tokens,
                    'output_tokens' => $this->estimated_output_tokens,
                    'reasoning_tokens' => $this->estimated_reasoning_tokens,
                    'total_tokens' => $this->estimated_input_text_tokens
                        + $this->estimated_input_image_tokens
                        + $this->estimated_output_tokens
                        + $this->estimated_reasoning_tokens,
                ],
                'actual' => $this->actual_total_tokens === null ? null : [
                    'input_text_tokens' => $this->actual_input_text_tokens,
                    'input_image_tokens' => $this->actual_input_image_tokens,
                    'output_tokens' => $this->actual_output_tokens,
                    'reasoning_tokens' => $this->actual_reasoning_tokens,
                    'total_tokens' => $this->actual_total_tokens,
                ],
            ],
            'costs' => [
                'estimated_provider_cost' => $this->estimated_provider_cost,
                'estimated_user_cost' => $this->estimated_user_cost,
                'actual_provider_cost' => $this->actual_provider_cost,
                'actual_user_cost' => $this->actual_user_cost,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
