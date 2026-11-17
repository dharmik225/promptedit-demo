<?php

namespace App\Services\Pixelforge;

use App\Services\Generation\TokenUsage;

/**
 * The cheapest and most expensive outcome of a generation before it runs.
 * Text and reasoning token counts are only known after completion, so the
 * real cost always falls between the two.
 */
final readonly class CostEstimate
{
    /** @param array<string, string> $rates */
    public function __construct(
        public TokenUsage $minimumUsage,
        public TokenUsage $maximumUsage,
        public string $minimumProviderCost,
        public string $maximumProviderCost,
        public string $minimumUserCost,
        public string $maximumUserCost,
        public string $markupPercentage,
        public array $rates,
    ) {}
}
