<?php

namespace Tests\Concerns;

use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

trait InteractsWithPixelforge
{
    protected const PIXELFORGE_URL = 'https://pixelforge.test/v1';

    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    protected const IMAGE_URL = 'https://pixelforge.test/images/generated.png';

    /** @return array<string, mixed> */
    protected function pricingPayload(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function providerGeneration(array $overrides = []): array
    {
        return [
            'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
            'object' => 'generation',
            'status' => 'queued',
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
            'started_at' => null,
            'completed_at' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function succeededGeneration(array $overrides = []): array
    {
        return $this->providerGeneration([
            'status' => 'succeeded',
            'image_url' => self::IMAGE_URL,
            'image_url_expires_at' => '2026-11-05T14:03:29Z',
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 3184,
                'total_tokens' => 10411,
            ],
            'started_at' => '2026-11-04T14:03:13Z',
            'completed_at' => '2026-11-04T14:03:29Z',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function failedGeneration(array $overrides = []): array
    {
        return $this->providerGeneration([
            'status' => 'failed',
            'error' => [
                'code' => 'generation_failed',
                'message' => 'The model could not produce an image for this prompt.',
            ],
            'started_at' => '2026-11-04T14:03:13Z',
            'completed_at' => '2026-11-04T14:03:29Z',
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $responses */
    protected function fakePixelforge(array $responses = []): void
    {
        Http::preventStrayRequests();

        Http::fake([
            ...$responses,
            self::PIXELFORGE_URL.'/pricing' => Http::response($this->pricingPayload()),
            self::IMAGE_URL => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $generation
     * @return array<string, mixed>
     */
    protected function webhookEvent(string $eventId, string $type, array $generation): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-04T14:03:29Z',
            'data' => ['object' => $generation],
        ];
    }

    /** @param array<string, mixed> $event */
    protected function postWebhook(array $event, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        $rawBody = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret ?? self::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            '/webhooks/pixelforge',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
                'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
            ],
            content: $rawBody,
        );
    }

    protected function assertCharged(Generation $generation, string $providerCost, string $userCost): void
    {
        $generation->refresh();

        $this->assertSame($providerCost, $generation->actual_provider_cost);
        $this->assertSame($userCost, $generation->actual_user_cost);
    }
}
