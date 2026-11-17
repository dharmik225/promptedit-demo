<?php

namespace App\Services\Pixelforge;

use App\Enums\WebhookEventStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Generation\GenerationStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PixelforgeWebhookService
{
    public function __construct(
        private readonly PixelforgeGenerationService $generationService,
        private readonly GenerationStatusService $statusService,
        private readonly ?string $webhookSecret,
        private readonly int $toleranceSeconds,
    ) {}

    /**
     * The Pixelforge-Signature header is "t=<unix>,v1=<hex>", where the hex is
     * HMAC-SHA256 of "<t>.<raw body>" keyed with the webhook signing secret.
     */
    public function hasValidSignature(string $rawBody, ?string $signatureHeader): bool
    {
        if (! $this->webhookSecret) {
            return false;
        }

        if (! $signatureHeader) {
            return false;
        }

        [$timestamp, $signatures] = $this->parseSignatureHeader($signatureHeader);

        if ($timestamp === null) {
            return false;
        }

        if (abs(now()->getTimestamp() - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->webhookSecret);

        foreach ($signatures as $signature) {
            if (hash_equals($expectedSignature, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Persist the event before acknowledging it. The unique (provider,
     * provider_event_id) index makes concurrent duplicate deliveries collapse
     * into one row; wasRecentlyCreated tells the caller which one won.
     *
     * @param  array<string, mixed>  $event
     */
    public function record(array $event, ?int $deliveryAttempt): WebhookEvent
    {
        $providerGenerationId = $event['data']['object']['id'] ?? null;

        return WebhookEvent::query()->createOrFirst(
            [
                'provider' => PixelforgeGenerationService::PROVIDER,
                'provider_event_id' => $event['id'],
            ],
            [
                'event_type' => $event['type'],
                'provider_generation_id' => is_string($providerGenerationId) ? $providerGenerationId : null,
                'payload' => $event,
                'delivery_attempt' => $deliveryAttempt,
                'processing_status' => WebhookEventStatus::Pending,
                'provider_created_at' => isset($event['created_at']) ? CarbonImmutable::parse($event['created_at']) : null,
            ],
        );
    }

    public function process(WebhookEvent $webhookEvent): void
    {
        DB::transaction(function () use ($webhookEvent): void {
            $lockedEvent = WebhookEvent::query()
                ->whereKey($webhookEvent->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedEvent->processing_status->isHandled()) {
                return;
            }

            if (! str_starts_with($lockedEvent->event_type, 'generation.')) {
                $this->finish($lockedEvent, WebhookEventStatus::Ignored, 'Not a generation event.');

                return;
            }

            $snapshot = $this->generationService->snapshot($lockedEvent->payload['data']['object']);
            $generation = $this->findGeneration($snapshot->providerGenerationId, $snapshot->metadata);

            if (! $generation) {
                $this->finish($lockedEvent, WebhookEventStatus::Ignored, 'No matching generation.');

                return;
            }

            $this->statusService->apply($generation, $snapshot);

            $lockedEvent->generation_id = $generation->id;
            $this->finish($lockedEvent, WebhookEventStatus::Processed, null);
        });
    }

    public function markFailed(WebhookEvent $webhookEvent, string $reason): void
    {
        WebhookEvent::query()
            ->whereKey($webhookEvent->getKey())
            ->where('processing_status', WebhookEventStatus::Pending)
            ->update([
                'processing_status' => WebhookEventStatus::Failed,
                'processing_error' => $reason,
            ]);
    }

    /**
     * A webhook can arrive before our create request returns, while the local
     * record has no provider id yet, so fall back to the UUID we sent as
     * metadata.
     *
     * @param  array<string, string>  $metadata
     */
    private function findGeneration(string $providerGenerationId, array $metadata): ?Generation
    {
        $generation = Generation::query()
            ->where('provider', PixelforgeGenerationService::PROVIDER)
            ->where('provider_generation_id', $providerGenerationId)
            ->first();

        if ($generation) {
            return $generation;
        }

        $generationUuid = $metadata[PixelforgeGenerationService::METADATA_GENERATION_KEY] ?? null;

        if (! $generationUuid) {
            return null;
        }

        return Generation::query()
            ->where('provider', PixelforgeGenerationService::PROVIDER)
            ->where('uuid', $generationUuid)
            ->whereNull('provider_generation_id')
            ->first();
    }

    private function finish(WebhookEvent $webhookEvent, WebhookEventStatus $status, ?string $note): void
    {
        $webhookEvent->processing_status = $status;
        $webhookEvent->processing_error = $note;
        $webhookEvent->processed_at = now();
        $webhookEvent->save();
    }

    /** @return array{0: ?int, 1: list<string>} */
    private function parseSignatureHeader(string $signatureHeader): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }
}
