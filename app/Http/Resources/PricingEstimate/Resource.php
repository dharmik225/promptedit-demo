<?php

namespace App\Http\Resources\PricingEstimate;

use App\Services\Pixelforge\CostEstimate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property CostEstimate $resource */
class Resource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'minimum' => [
                'tokens' => [
                    ...$this->resource->minimumUsage->toArray(),
                    'total_tokens' => $this->resource->minimumUsage->total(),
                ],
                'provider_cost' => $this->resource->minimumProviderCost,
                'user_cost' => $this->resource->minimumUserCost,
            ],
            'maximum' => [
                'tokens' => [
                    ...$this->resource->maximumUsage->toArray(),
                    'total_tokens' => $this->resource->maximumUsage->total(),
                ],
                'provider_cost' => $this->resource->maximumProviderCost,
                'user_cost' => $this->resource->maximumUserCost,
            ],
            'markup_percentage' => $this->resource->markupPercentage,
            'rates' => $this->resource->rates,
        ];
    }
}
