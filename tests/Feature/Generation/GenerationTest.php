<?php

namespace Tests\Feature\Generation;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithPixelforge;
use Tests\TestCase;

class GenerationTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /** @return array<string, mixed> */
    private function validInput(array $overrides = []): array
    {
        return [
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_image_urls' => [],
            ...$overrides,
        ];
    }

    private function pendingGeneration(): Generation
    {
        return Generation::factory()->create();
    }

    public function test_guests_cannot_use_generation_pages(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/generations')->assertRedirect('/login');
        $this->post('/generations', $this->validInput())->assertRedirect('/login');
        $this->getJson('/generations/pricing-estimate')->assertUnauthorized();
    }

    public function test_dashboard_offers_the_providers_sizes_and_qualities(): void
    {
        $this->fakePixelforge();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('options.sizes', ['1024x1024', '1536x1024', '1024x1536'])
                ->where('options.qualities', ['low', 'medium', 'high'])
                ->where('options.markup_percentage', '25'));
    }

    public function test_user_can_create_a_generation_that_pixelforge_accepts(): void
    {
        $this->fakePixelforge([
            self::PIXELFORGE_URL.'/generations' => Http::response($this->providerGeneration(), 202),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/generations', $this->validInput([
                'reference_image_urls' => ['https://example.com/style.png', ''],
            ]))
            ->assertRedirect('/dashboard');

        $generation = $user->generations()->sole();

        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $generation->provider_generation_id);
        $this->assertSame(['https://example.com/style.png'], $generation->reference_image_urls);
        $this->assertSame(10, $generation->estimated_input_text_tokens);
        $this->assertSame(765, $generation->estimated_input_image_tokens);
        $this->assertSame(2000, $generation->estimated_reasoning_tokens);
        $this->assertSame('0.076700000000', $generation->estimated_provider_cost);
        $this->assertSame('0.095875000000', $generation->estimated_user_cost);
        $this->assertNull($generation->actual_user_cost);
        $this->assertNotNull($generation->submitted_at);

        Http::assertSent(fn (Request $request) => $request->url() === self::PIXELFORGE_URL.'/generations'
            && $request->method() === 'POST'
            && $request->header('Idempotency-Key')[0] === $generation->uuid
            && $request->header('Authorization')[0] === 'Bearer pf_test_key'
            && $request['metadata'] === ['generation_uuid' => $generation->uuid]
            && $request['reference_images'] === ['https://example.com/style.png']);
    }

    public function test_generation_input_is_validated(): void
    {
        $this->fakePixelforge();
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post('/generations', [
                'prompt' => '',
                'size' => '512x512',
                'quality' => 'ultra',
                'reference_image_urls' => ['not-a-url', 'ftp://example.com/a.png', 'https://a.test/1.png', 'https://a.test/2.png', 'https://a.test/3.png'],
            ])
            ->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_image_urls', 'reference_image_urls.0', 'reference_image_urls.1']);

        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    }

    public function test_prompt_longer_than_the_provider_limit_is_rejected(): void
    {
        $this->fakePixelforge();

        $this->actingAs(User::factory()->create())
            ->post('/generations', $this->validInput(['prompt' => str_repeat('a', 4001)]))
            ->assertSessionHasErrors('prompt');
    }

    public function test_content_policy_rejection_fails_the_generation_for_free(): void
    {
        $this->fakePixelforge([
            self::PIXELFORGE_URL.'/generations' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'content_policy_violation',
                    'message' => 'The prompt was rejected.',
                ],
            ], 400),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', $this->validInput(['prompt' => 'Something [blocked]']));

        $generation = $user->generations()->sole();

        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertNull($generation->provider_generation_id);
        $this->assertCharged($generation, '0.000000000000', '0.000000000000');
    }

    public function test_daily_limit_is_not_retried(): void
    {
        $this->fakePixelforge([
            self::PIXELFORGE_URL.'/generations' => Http::response([
                'error' => ['type' => 'rate_limit_error', 'code' => 'daily_limit_exceeded', 'message' => 'Daily quota used.'],
            ], 429),
        ]);
        $generation = $this->pendingGeneration();

        $job = $this->runSubmission($generation);

        $job->assertNotReleased();
        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertSame('daily_limit_exceeded', $generation->error_code);
    }

    public function test_concurrency_limit_releases_the_job_for_retry_after(): void
    {
        $this->fakePixelforge([
            self::PIXELFORGE_URL.'/generations' => Http::response([
                'error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Too many generations.'],
            ], 429, ['Retry-After' => '5']),
        ]);
        $generation = $this->pendingGeneration();

        $job = $this->runSubmission($generation);

        $job->assertReleased(5);
        $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
        $this->assertSame(1, $generation->submission_attempts);
    }

    public function test_rate_limit_waits_at_least_the_retry_after_header(): void
    {
        $this->fakePixelforge([
            self::PIXELFORGE_URL.'/generations' => Http::response([
                'error' => ['type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded', 'message' => 'Too many requests.'],
            ], 429, ['Retry-After' => '42']),
        ]);

        $job = $this->runSubmission($this->pendingGeneration());

        $job->assertReleased(42);
    }

    public function test_overloaded_provider_fails_the_generation_after_the_last_attempt(): void
    {
        $this->fakePixelforge([
            self::PIXELFORGE_URL.'/generations' => Http::response([
                'error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Overloaded.'],
            ], 503, ['Retry-After' => '2']),
        ]);
        $generation = Generation::factory()->create(['submission_attempts' => 4]);

        $job = $this->runSubmission($generation);

        $job->assertNotReleased();
        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertSame('overloaded', $generation->error_code);
        $this->assertCharged($generation, '0.000000000000', '0.000000000000');
    }

    public function test_timed_out_submission_is_retried_with_the_same_idempotency_key(): void
    {
        $this->fakePixelforge([
            self::PIXELFORGE_URL.'/generations' => Http::sequence()
                ->pushFailedConnection()
                ->push($this->providerGeneration(), 202, ['Idempotent-Replayed' => 'true']),
        ]);
        $generation = $this->pendingGeneration();

        $this->runSubmission($generation)->assertReleased(5);
        $this->runSubmission($generation)->assertNotReleased();

        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);

        $idempotencyKeys = Http::recorded(fn (Request $request) => $request->method() === 'POST')
            ->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0])
            ->unique();

        $this->assertSame([$generation->uuid], $idempotencyKeys->values()->all());
    }

    public function test_already_submitted_generation_is_not_submitted_again(): void
    {
        $this->fakePixelforge();
        $generation = Generation::factory()->queued()->create();

        $this->runSubmission($generation);

        Http::assertNothingSent();
    }

    public function test_history_only_lists_the_users_own_generations(): void
    {
        $user = User::factory()->create();
        $ownGeneration = Generation::factory()->for($user)->create();
        Generation::factory()->create();

        $this->actingAs($user)
            ->get('/generations')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('generations/Index')
                ->has('generations.data', 1)
                ->where('generations.data.0.id', $ownGeneration->uuid)
                ->where('generations.meta.total', 1));
    }

    public function test_history_can_be_filtered_by_status(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create();
        Generation::factory()->for($user)->failed()->create();

        $this->actingAs($user)
            ->get('/generations?status=failed')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('generations.data', 1)
                ->where('generations.data.0.status', 'failed'));
    }

    public function test_user_can_view_their_generation(): void
    {
        $generation = Generation::factory()->succeeded()->create();

        $this->actingAs($generation->user)
            ->get("/generations/{$generation->uuid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('generations/Show')
                ->where('generation.data.id', $generation->uuid)
                ->where('generation.data.costs.actual_user_cost', '0.070700000000'));
    }

    public function test_user_cannot_view_another_users_generation(): void
    {
        $generation = Generation::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/generations/{$generation->uuid}")
            ->assertNotFound();
    }

    public function test_pricing_estimate_endpoint_returns_the_cost_range(): void
    {
        $this->fakePixelforge();

        $this->actingAs(User::factory()->create())
            ->getJson('/generations/pricing-estimate?prompt_characters=400&size=1536x1024&quality=high&reference_image_count=2')
            ->assertOk()
            ->assertJsonPath('data.maximum.provider_cost', '0.373875000000')
            ->assertJsonPath('data.maximum.user_cost', '0.467343750000')
            ->assertJsonPath('data.minimum.provider_cost', '0.286225000000')
            ->assertJsonPath('data.markup_percentage', '25');
    }

    public function test_pricing_estimate_is_validated(): void
    {
        $this->fakePixelforge();

        $this->actingAs(User::factory()->create())
            ->getJson('/generations/pricing-estimate?prompt_characters=-1&size=1x1&quality=high&reference_image_count=9')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt_characters', 'size', 'reference_image_count']);
    }

    private function runSubmission(Generation $generation): SubmitGeneration
    {
        $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        return $job;
    }
}
