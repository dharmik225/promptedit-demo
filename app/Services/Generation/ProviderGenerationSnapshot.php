<?php

namespace App\Services\Generation;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;

/**
 * A provider-neutral view of a generation as the provider reported it,
 * produced by the create response and by webhooks so they share one update path.
 */
final readonly class ProviderGenerationSnapshot
{
    /**
     * @param  array<string, string>  $rates
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $providerGenerationId,
        public GenerationStatus $status,
        public array $rates,
        public ?TokenUsage $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
        public array $metadata,
    ) {
    }
}
