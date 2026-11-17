<?php

namespace App\Services\Generation;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PixelforgeGenerationService;
use App\Services\Pixelforge\PixelforgePricingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class GenerationService
{
    public const PER_PAGE = 12;

    public const RECENT_LIMIT = 6;

    public function __construct(private readonly PixelforgePricingService $pricingService) {}

    /** @return LengthAwarePaginator<int, Generation> */
    public function collection(User $user, ?GenerationStatus $status): LengthAwarePaginator
    {
        return $user->generations()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /** @return Collection<int, Generation> */
    public function recent(User $user): Collection
    {
        return $user->generations()
            ->latest()
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * Records the generation with its worst-case estimate, then hands the
     * provider call to a queued job so the request returns immediately.
     *
     * @param array{
     *     prompt: string,
     *     size: string,
     *     quality: string,
     *     reference_image_urls?: list<string>
     * } $inputs
     */
    public function create(User $user, array $inputs): Generation
    {
        $referenceImageUrls = $inputs['reference_image_urls'] ?? [];

        $estimate = $this->pricingService->estimate(
            promptCharacters: mb_strlen($inputs['prompt']),
            size: $inputs['size'],
            quality: $inputs['quality'],
            referenceImageCount: count($referenceImageUrls),
        );

        $generation = $user->generations()->create([
            'provider' => PixelforgeGenerationService::PROVIDER,
            'prompt' => $inputs['prompt'],
            'size' => $inputs['size'],
            'quality' => $inputs['quality'],
            'reference_image_urls' => $referenceImageUrls,
            'status' => GenerationStatus::Pending,
            'markup_percentage' => $estimate->markupPercentage,
            'rates' => $estimate->rates,
            'estimated_input_text_tokens' => $estimate->maximumUsage->inputTextTokens,
            'estimated_input_image_tokens' => $estimate->maximumUsage->inputImageTokens,
            'estimated_output_tokens' => $estimate->maximumUsage->outputTokens,
            'estimated_reasoning_tokens' => $estimate->maximumUsage->reasoningTokens,
            'estimated_provider_cost' => $estimate->maximumProviderCost,
            'estimated_user_cost' => $estimate->maximumUserCost,
        ]);

        SubmitGeneration::dispatch($generation);

        return $generation;
    }

    /**
     * Sizes and qualities come from Pixelforge's pricing table, so the form
     * only offers what the provider currently supports.
     *
     * @return array{
     *     available: bool,
     *     sizes: list<string>,
     *     qualities: list<string>,
     *     max_reference_images: int,
     *     max_prompt_length: int,
     *     markup_percentage: string
     * }
     */
    public function formOptions(): array
    {
        $options = [
            'available' => false,
            'sizes' => [],
            'qualities' => [],
            'max_reference_images' => (int) config('services.pixelforge.max_reference_images'),
            'max_prompt_length' => (int) config('services.pixelforge.max_prompt_length'),
            'markup_percentage' => $this->pricingService->markupPercentage(),
        ];

        try {
            $pricing = $this->pricingService->pricing();
        } catch (PixelforgeException) {
            return $options;
        }

        return [
            ...$options,
            'available' => true,
            'sizes' => $pricing->sizes(),
            'qualities' => $pricing->qualities(),
        ];
    }
}
