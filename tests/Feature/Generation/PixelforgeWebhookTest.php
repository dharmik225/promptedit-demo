<?php

namespace Tests\Feature\Generation;

use App\Enums\GenerationStatus;
use App\Enums\WebhookEventStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\PixelforgeWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithPixelforge;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    private const PROVIDER_GENERATION_ID = 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->fakePixelforge();
    }

    private function processingGeneration(): Generation
    {
        return Generation::factory()->processing()->create([
            'provider_generation_id' => self::PROVIDER_GENERATION_ID,
        ]);
    }

    public function test_succeeded_event_completes_and_charges_the_generation(): void
    {
        $generation = $this->processingGeneration();

        $this->postWebhook($this->webhookEvent('evt_1', 'generation.succeeded', $this->succeededGeneration()))
            ->assertNoContent();

        $generation->refresh();

        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(12, $generation->actual_input_text_tokens);
        $this->assertSame(765, $generation->actual_input_image_tokens);
        $this->assertSame(6450, $generation->actual_output_tokens);
        $this->assertSame(3184, $generation->actual_reasoning_tokens);
        $this->assertSame(10411, $generation->actual_total_tokens);
        $this->assertCharged($generation, '0.305510000000', '0.381887500000');
        $this->assertSame('2026-11-04T14:03:29+00:00', $generation->completed_at->toIso8601String());
        $this->assertSame('generations/'.$generation->uuid.'.png', $generation->image_path);
        Storage::disk('public')->assertExists($generation->image_path);

        $this->assertDatabaseHas('webhook_events', [
            'provider_event_id' => 'evt_1',
            'generation_id' => $generation->id,
            'processing_status' => WebhookEventStatus::Processed->value,
        ]);
    }

    public function test_failed_event_costs_nothing(): void
    {
        $generation = $this->processingGeneration();

        $this->postWebhook($this->webhookEvent('evt_1', 'generation.failed', $this->failedGeneration()))
            ->assertNoContent();

        $generation->refresh();

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertNull($generation->actual_total_tokens);
        $this->assertCharged($generation, '0.000000000000', '0.000000000000');
    }

    public function test_invalid_signature_is_rejected_without_changes(): void
    {
        $generation = $this->processingGeneration();

        $this->postWebhook(
            $this->webhookEvent('evt_1', 'generation.succeeded', $this->succeededGeneration()),
            secret: 'whsec_wrong_secret',
        )->assertStatus(400);

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->postJson('/webhooks/pixelforge', $this->webhookEvent('evt_1', 'ping', []))
            ->assertStatus(400);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_replayed_old_signature_is_rejected(): void
    {
        $this->postWebhook(
            $this->webhookEvent('evt_1', 'generation.succeeded', $this->succeededGeneration()),
            timestamp: now()->subMinutes(10)->getTimestamp(),
        )->assertStatus(400);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_tampered_body_is_rejected(): void
    {
        $generation = $this->processingGeneration();
        $event = $this->webhookEvent('evt_1', 'generation.succeeded', $this->succeededGeneration());
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.json_encode($event), self::WEBHOOK_SECRET);
        $event['data']['object']['usage']['output_tokens'] = 1;

        $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], content: json_encode($event))->assertStatus(400);

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
    }

    public function test_signed_but_malformed_payload_is_rejected_without_changes(): void
    {
        $generation = $this->processingGeneration();

        $this->postWebhook($this->webhookEvent('evt_1', 'generation.succeeded', $this->succeededGeneration(['usage' => null])))
            ->assertUnprocessable();

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_duplicate_delivery_is_processed_once(): void
    {
        $generation = $this->processingGeneration();
        $event = $this->webhookEvent('evt_1', 'generation.succeeded', $this->succeededGeneration());

        $this->postWebhook($event)->assertNoContent();
        $firstProcessedAt = WebhookEvent::query()->sole()->processed_at;

        $this->travel(5)->seconds();
        $this->postWebhook($event)->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertEquals($firstProcessedAt, WebhookEvent::query()->sole()->processed_at);
        $this->assertCharged($generation, '0.305510000000', '0.381887500000');
    }

    public function test_second_success_event_does_not_charge_again(): void
    {
        $generation = $this->processingGeneration();

        $this->postWebhook($this->webhookEvent('evt_1', 'generation.succeeded', $this->succeededGeneration()));
        $this->postWebhook($this->webhookEvent('evt_2', 'generation.succeeded', $this->succeededGeneration([
            'usage' => [
                'input_text_tokens' => 999,
                'input_image_tokens' => 999,
                'output_tokens' => 999999,
                'reasoning_tokens' => 999,
                'total_tokens' => 1002996,
            ],
        ])));

        $this->assertDatabaseCount('webhook_events', 2);
        $this->assertSame(10411, $generation->refresh()->actual_total_tokens);
        $this->assertCharged($generation, '0.305510000000', '0.381887500000');
    }

    public function test_late_processing_event_does_not_regress_a_finished_generation(): void
    {
        $generation = $this->processingGeneration();

        $this->postWebhook($this->webhookEvent('evt_2', 'generation.succeeded', $this->succeededGeneration()));
        $this->postWebhook($this->webhookEvent('evt_1', 'generation.processing', $this->providerGeneration([
            'status' => 'processing',
            'started_at' => '2026-11-04T14:03:13Z',
        ])))->assertNoContent();

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
        $this->assertCharged($generation, '0.305510000000', '0.381887500000');
    }

    public function test_failure_cannot_overwrite_a_success(): void
    {
        $generation = $this->processingGeneration();

        $this->postWebhook($this->webhookEvent('evt_1', 'generation.succeeded', $this->succeededGeneration()));
        $this->postWebhook($this->webhookEvent('evt_2', 'generation.failed', $this->failedGeneration()));

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
        $this->assertNull($generation->error_code);
        $this->assertCharged($generation, '0.305510000000', '0.381887500000');
    }

    public function test_webhook_arriving_before_the_create_response_is_matched_by_metadata(): void
    {
        $generation = Generation::factory()->create();

        $this->postWebhook($this->webhookEvent('evt_1', 'generation.processing', $this->providerGeneration([
            'status' => 'processing',
            'metadata' => ['generation_uuid' => $generation->uuid],
        ])))->assertNoContent();

        $generation->refresh();

        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertSame(self::PROVIDER_GENERATION_ID, $generation->provider_generation_id);
    }

    public function test_event_for_an_unknown_generation_is_acknowledged_and_ignored(): void
    {
        $this->postWebhook($this->webhookEvent('evt_1', 'generation.succeeded', $this->succeededGeneration()))
            ->assertNoContent();

        $this->assertDatabaseHas('webhook_events', [
            'provider_event_id' => 'evt_1',
            'processing_status' => WebhookEventStatus::Ignored->value,
            'generation_id' => null,
        ]);
    }

    public function test_ping_event_is_acknowledged(): void
    {
        $this->postWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => []])
            ->assertNoContent();

        $this->assertDatabaseHas('webhook_events', [
            'provider_event_id' => 'evt_ping',
            'processing_status' => WebhookEventStatus::Ignored->value,
        ]);
    }

    public function test_processing_the_same_stored_event_twice_has_no_effect(): void
    {
        $generation = $this->processingGeneration();
        $webhookService = app(PixelforgeWebhookService::class);
        $webhookEvent = $webhookService->record(
            $this->webhookEvent('evt_1', 'generation.succeeded', $this->succeededGeneration()),
            1,
        );

        $webhookService->process($webhookEvent);
        $generation->refresh()->update(['actual_user_cost' => '1.000000000000']);
        $webhookService->process($webhookEvent);

        $this->assertSame('1.000000000000', $generation->refresh()->actual_user_cost);
    }

    public function test_concurrent_duplicate_records_collapse_into_one_event(): void
    {
        $webhookService = app(PixelforgeWebhookService::class);
        $event = $this->webhookEvent('evt_1', 'generation.succeeded', $this->succeededGeneration());

        $firstDelivery = $webhookService->record($event, 1);
        $secondDelivery = $webhookService->record($event, 2);

        $this->assertTrue($firstDelivery->wasRecentlyCreated);
        $this->assertFalse($secondDelivery->wasRecentlyCreated);
        $this->assertSame($firstDelivery->id, $secondDelivery->id);
    }
}
