<?php

namespace App\Services\Generation;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgePricingService;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * The only place a generation's status and charges change. Every caller
 * (submission, webhooks) goes through here, under a row lock, so
 * statuses only move forward and a generation is charged at most once.
 */
class GenerationStatusService
{
    public function __construct(private readonly PixelforgePricingService $pricingService)
    {
    }

    public function apply(Generation $generation, ProviderGenerationSnapshot $snapshot): Generation
    {
        return DB::transaction(function () use ($generation, $snapshot): Generation {
            $lockedGeneration = $this->lock($generation);

            $this->guardProviderIdentity($lockedGeneration, $snapshot);

            $lockedGeneration->provider_generation_id ??= $snapshot->providerGenerationId;
            $lockedGeneration->submitted_at ??= now();
            $lockedGeneration->last_synced_at = now();

            if ($this->isStaleOrFinal($lockedGeneration, $snapshot)) {
                $lockedGeneration->save();

                return $lockedGeneration;
            }

            $this->recordProviderDetails($lockedGeneration, $snapshot);

            match ($snapshot->status) {
                GenerationStatus::Succeeded => $this->markSucceeded($lockedGeneration, $snapshot),
                GenerationStatus::Failed => $this->markFailedFromProvider($lockedGeneration, $snapshot),
                default => $lockedGeneration->status = $snapshot->status,
            };

            $lockedGeneration->save();

            if ($lockedGeneration->status === GenerationStatus::Succeeded) {
                StoreGenerationImage::dispatch($lockedGeneration)->afterCommit();
            }

            return $lockedGeneration;
        });
    }

    /**
     * Fail a generation for a reason that did not come from a provider
     * snapshot, such as a rejected submission. Never touches final records.
     */
    public function markFailed(Generation $generation, string $errorCode, string $errorMessage): Generation
    {
        return DB::transaction(function () use ($generation, $errorCode, $errorMessage): Generation {
            $lockedGeneration = $this->lock($generation);

            if ($lockedGeneration->status->isFinal()) {
                return $lockedGeneration;
            }

            $this->applyFailure($lockedGeneration, $errorCode, $errorMessage, null);
            $lockedGeneration->save();

            return $lockedGeneration;
        });
    }

    private function lock(Generation $generation): Generation
    {
        return Generation::query()
            ->whereKey($generation->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function guardProviderIdentity(Generation $generation, ProviderGenerationSnapshot $snapshot): void
    {
        if ($generation->provider_generation_id === null) {
            return;
        }

        if ($generation->provider_generation_id === $snapshot->providerGenerationId) {
            return;
        }

        throw new UnexpectedValueException("Snapshot for [{$snapshot->providerGenerationId}] does not belong to generation [{$generation->uuid}].");
    }

    /**
     * Events arrive out of order: a retried "processing" event can land after
     * "succeeded". Final generations never change, and a snapshot that is
     * behind the stored status is ignored.
     */
    private function isStaleOrFinal(Generation $generation, ProviderGenerationSnapshot $snapshot): bool
    {
        if ($generation->status->isFinal()) {
            return true;
        }

        return $snapshot->status->rank() < $generation->status->rank();
    }

    private function recordProviderDetails(Generation $generation, ProviderGenerationSnapshot $snapshot): void
    {
        if ($snapshot->rates !== []) {
            $generation->rates = $snapshot->rates;
        }

        $generation->provider_metadata = $snapshot->metadata;
        $generation->started_at = $snapshot->startedAt ?? $generation->started_at;
    }

    private function markSucceeded(Generation $generation, ProviderGenerationSnapshot $snapshot): void
    {
        if ($snapshot->usage === null) {
            throw new UnexpectedValueException("Succeeded generation [{$snapshot->providerGenerationId}] has no token usage.");
        }

        $actualProviderCost = $this->pricingService->providerCost($snapshot->usage, $generation->rates);

        $generation->status = GenerationStatus::Succeeded;
        $generation->actual_input_text_tokens = $snapshot->usage->inputTextTokens;
        $generation->actual_input_image_tokens = $snapshot->usage->inputImageTokens;
        $generation->actual_output_tokens = $snapshot->usage->outputTokens;
        $generation->actual_reasoning_tokens = $snapshot->usage->reasoningTokens;
        $generation->actual_total_tokens = $snapshot->usage->total();
        $generation->actual_provider_cost = $actualProviderCost;
        $generation->actual_user_cost = $this->pricingService->userCost($actualProviderCost, (string) $generation->markup_percentage);
        $generation->image_url = $snapshot->imageUrl;
        $generation->image_url_expires_at = $snapshot->imageUrlExpiresAt;
        $generation->error_code = null;
        $generation->error_message = null;
        $generation->completed_at = $snapshot->completedAt ?? now();
    }

    private function markFailedFromProvider(Generation $generation, ProviderGenerationSnapshot $snapshot): void
    {
        $this->applyFailure(
            $generation,
            $snapshot->errorCode ?? 'generation_failed',
            $snapshot->errorMessage ?? 'The provider could not generate this image.',
            $snapshot,
        );
    }

    /** Failed generations are never billed, by Pixelforge or by us. */
    private function applyFailure(Generation $generation, string $errorCode, string $errorMessage, ?ProviderGenerationSnapshot $snapshot): void
    {
        $generation->status = GenerationStatus::Failed;
        $generation->actual_input_text_tokens = null;
        $generation->actual_input_image_tokens = null;
        $generation->actual_output_tokens = null;
        $generation->actual_reasoning_tokens = null;
        $generation->actual_total_tokens = null;
        $generation->actual_provider_cost = $this->pricingService->zeroCost();
        $generation->actual_user_cost = $this->pricingService->zeroCost();
        $generation->error_code = $errorCode;
        $generation->error_message = $errorMessage;
        $generation->completed_at = $snapshot?->completedAt ?? now();
    }
}
