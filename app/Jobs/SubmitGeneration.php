<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generation\GenerationStatusService;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\PixelforgeGenerationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SubmitGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** @var list<int> */
    private const BACKOFF_SECONDS = [5, 10, 20, 40, 60];

    public int $tries = 10;

    public int $uniqueFor = 600;

    public function __construct(public Generation $generation) {}

    public function uniqueId(): string
    {
        return (string) $this->generation->id;
    }

    public function handle(PixelforgeGenerationService $generationService, GenerationStatusService $statusService): void
    {
        $generation = $this->generation->fresh();

        if (! $generation) {
            return;
        }

        if ($generation->status !== GenerationStatus::Pending) {
            return;
        }

        if ($generation->provider_generation_id !== null) {
            return;
        }

        $generation->increment('submission_attempts');

        try {
            $generationService->submit($generation);
        } catch (PixelforgeException $exception) {
            $this->handleProviderError($generation, $exception, $statusService);
        }
    }

    /**
     * Rejections like content policy, validation or the daily quota fail the
     * generation for free. Temporary errors are retried with the same
     * Idempotency-Key, waiting at least as long as Retry-After says.
     */
    private function handleProviderError(Generation $generation, PixelforgeException $exception, GenerationStatusService $statusService): void
    {
        $errorCode = $exception->errorCode ?? 'submission_failed';

        if (! $exception->isRetryable()) {
            $statusService->markFailed($generation, $errorCode, $exception->getMessage());

            return;
        }

        $maxAttempts = (int) config('services.pixelforge.max_submission_attempts');

        if ($generation->submission_attempts >= $maxAttempts) {
            $statusService->markFailed(
                $generation,
                $errorCode,
                "Pixelforge was unavailable after {$generation->submission_attempts} attempts: {$exception->getMessage()}",
            );

            return;
        }

        $delay = $this->backoffSeconds($generation->submission_attempts, $exception->retryAfter);

        Log::info('Retrying Pixelforge submission.', [
            'generation' => $generation->uuid,
            'attempt' => $generation->submission_attempts,
            'code' => $errorCode,
            'delay' => $delay,
        ]);

        $this->release($delay);
    }

    private function backoffSeconds(int $attempt, ?int $retryAfter): int
    {
        $scheduledDelay = self::BACKOFF_SECONDS[min($attempt, count(self::BACKOFF_SECONDS)) - 1];

        return max($scheduledDelay, $retryAfter ?? 0);
    }
}
