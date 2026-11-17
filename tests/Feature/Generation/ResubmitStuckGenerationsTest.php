<?php

namespace Tests\Feature\Generation;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResubmitStuckGenerationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stuck_submission_is_resubmitted(): void
    {
        Queue::fake();
        $stuckGeneration = Generation::factory()->create(['updated_at' => now()->subMinutes(5)]);
        Generation::factory()->create();

        $this->artisan('generations:resubmit-stuck')->assertSuccessful();

        Queue::assertPushed(SubmitGeneration::class, 1);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($stuckGeneration));
    }

    public function test_accepted_generations_are_never_polled(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        Generation::factory()->queued()->create(['updated_at' => now()->subHour()]);
        Generation::factory()->processing()->create(['updated_at' => now()->subHour()]);

        $this->artisan('generations:resubmit-stuck')->assertSuccessful();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
