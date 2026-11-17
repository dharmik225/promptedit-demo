<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResubmitStuckGenerationsCommand extends Command
{
    private const BATCH_SIZE = 25;

    private const STALE_SUBMISSION_MINUTES = 2;

    protected $signature = 'generations:resubmit-stuck';

    protected $description = 'Re-queue generations whose submission to Pixelforge never completed.';

    /**
     * Only generations Pixelforge has never accepted are picked up. Their
     * status updates after acceptance arrive by webhook. Resubmitting reuses
     * the same Idempotency-Key, so it cannot create a second generation.
     */
    public function handle(): int
    {
        $stuckSubmissions = Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->whereNull('provider_generation_id')
            ->where('updated_at', '<=', now()->subMinutes(self::STALE_SUBMISSION_MINUTES))
            ->limit(self::BATCH_SIZE)
            ->get();

        $stuckSubmissions->each(function (Generation $generation): void {
            $this->info("Resubmitting generation `{$generation->uuid}`...");
            SubmitGeneration::dispatch($generation);
        });

        if ($stuckSubmissions->isNotEmpty()) {
            Log::info('generations:resubmit-stuck dispatched jobs.', ['resubmitted' => $stuckSubmissions->count()]);
        }

        $this->comment("Resubmitted {$stuckSubmissions->count()} generations.");

        return self::SUCCESS;
    }
}
