<?php

namespace App\Services\Pixelforge;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generation\GenerationStatusService;
use App\Services\Generation\ProviderGenerationSnapshot;
use App\Services\Generation\TokenUsage;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

class PixelforgeGenerationService
{
    public const PROVIDER = 'pixelforge';

    public const METADATA_GENERATION_KEY = 'generation_uuid';

    private const PROVIDER_STATUSES = ['queued', 'processing', 'succeeded', 'failed'];

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly GenerationStatusService $statusService,
    ) {
    }

    /**
     * The local UUID doubles as the Idempotency-Key, so retrying after a
     * timeout returns the generation Pixelforge already accepted instead of
     * creating (and billing) a second one.
     */
    public function submit(Generation $generation): Generation
    {
        $providerGeneration = $this->client->createGeneration($this->payload($generation), $generation->uuid);

        return $this->statusService->apply($generation, $this->snapshot($providerGeneration));
    }

    /** @param array<string, mixed> $providerGeneration */
    public function snapshot(array $providerGeneration): ProviderGenerationSnapshot
    {
        $providerGenerationId = $providerGeneration['id'] ?? null;
        $status = $providerGeneration['status'] ?? null;

        if (! is_string($providerGenerationId) || ! str_starts_with($providerGenerationId, 'gen_')) {
            throw new UnexpectedValueException('Pixelforge generation has no valid id.');
        }

        if (! in_array($status, self::PROVIDER_STATUSES, true)) {
            throw new UnexpectedValueException("Pixelforge generation [{$providerGenerationId}] has an unknown status.");
        }

        $error = is_array($providerGeneration['error'] ?? null) ? $providerGeneration['error'] : [];

        return new ProviderGenerationSnapshot(
            providerGenerationId: $providerGenerationId,
            status: GenerationStatus::from($status),
            rates: is_array($providerGeneration['rates'] ?? null) ? PixelforgePricing::parseRates($providerGeneration['rates']) : [],
            usage: $this->usage($providerGeneration['usage'] ?? null),
            imageUrl: $this->stringOrNull($providerGeneration['image_url'] ?? null),
            imageUrlExpiresAt: $this->timestamp($providerGeneration['image_url_expires_at'] ?? null),
            errorCode: $this->stringOrNull($error['code'] ?? null),
            errorMessage: $this->stringOrNull($error['message'] ?? null),
            startedAt: $this->timestamp($providerGeneration['started_at'] ?? null),
            completedAt: $this->timestamp($providerGeneration['completed_at'] ?? null),
            metadata: $this->metadata($providerGeneration['metadata'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    private function payload(Generation $generation): array
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'metadata' => [self::METADATA_GENERATION_KEY => $generation->uuid],
        ];

        if ($generation->reference_image_urls !== []) {
            $payload['reference_images'] = $generation->reference_image_urls;
        }

        return $payload;
    }

    private function usage(mixed $usage): ?TokenUsage
    {
        if (! is_array($usage)) {
            return null;
        }

        return new TokenUsage(
            inputTextTokens: (int) ($usage['input_text_tokens'] ?? 0),
            inputImageTokens: (int) ($usage['input_image_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            reasoningTokens: (int) ($usage['reasoning_tokens'] ?? 0),
        );
    }

    /** @return array<string, string> */
    private function metadata(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        return array_map(fn (mixed $value): string => (string) $value, $metadata);
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
