<?php

namespace App\Http\Controllers;

use App\Http\Requests\Generation\PricingEstimateRequest;
use App\Http\Resources\PricingEstimate\Resource;
use App\Services\Pixelforge\PixelforgePricingService;

class GenerationPricingEstimateController extends Controller
{
    public function __invoke(PricingEstimateRequest $request, PixelforgePricingService $pricingService): Resource
    {
        $estimate = $pricingService->estimate(
            promptCharacters: (int) $request->validated('prompt_characters'),
            size: $request->validated('size'),
            quality: $request->validated('quality'),
            referenceImageCount: (int) $request->validated('reference_image_count'),
        );

        return new Resource($estimate);
    }
}
