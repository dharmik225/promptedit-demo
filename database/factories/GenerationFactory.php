<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'pixelforge',
            'provider_generation_id' => null,
            'prompt' => fake()->sentence(8),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_image_urls' => [],
            'status' => GenerationStatus::Pending,
            'markup_percentage' => '25',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'estimated_input_text_tokens' => 15,
            'estimated_input_image_tokens' => 0,
            'estimated_output_tokens' => 1100,
            'estimated_reasoning_tokens' => 2000,
            'estimated_provider_cost' => '0.069075000000',
            'estimated_user_cost' => '0.086343750000',
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Queued,
            'provider_generation_id' => 'gen_'.Str::upper(Str::random(26)),
            'submission_attempts' => 1,
            'submitted_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function succeeded(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Succeeded,
            'actual_input_text_tokens' => 12,
            'actual_input_image_tokens' => 0,
            'actual_output_tokens' => 1100,
            'actual_reasoning_tokens' => 1000,
            'actual_total_tokens' => 2112,
            'actual_provider_cost' => '0.056560000000',
            'actual_user_cost' => '0.070700000000',
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png',
            'image_url_expires_at' => now()->addDay(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Failed,
            'actual_provider_cost' => '0',
            'actual_user_cost' => '0',
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image for the prompt.',
            'completed_at' => now(),
        ]);
    }
}
