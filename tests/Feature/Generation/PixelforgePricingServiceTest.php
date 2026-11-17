<?php

namespace Tests\Feature\Generation;

use App\Services\Generation\TokenUsage;
use App\Services\Pixelforge\PixelforgePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithPixelforge;
use Tests\TestCase;

class PixelforgePricingServiceTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    private const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePixelforge();
    }

    private function pricingService(): PixelforgePricingService
    {
        return app(PixelforgePricingService::class);
    }

    public function test_maximum_estimate_matches_the_documented_worked_example(): void
    {
        $estimate = $this->pricingService()->estimate(400, '1536x1024', 'high', 2);

        $this->assertSame(115, $estimate->maximumUsage->inputTextTokens);
        $this->assertSame(1530, $estimate->maximumUsage->inputImageTokens);
        $this->assertSame(6450, $estimate->maximumUsage->outputTokens);
        $this->assertSame(8000, $estimate->maximumUsage->reasoningTokens);
        $this->assertSame('0.373875000000', $estimate->maximumProviderCost);
        $this->assertSame('0.467343750000', $estimate->maximumUserCost);
    }

    public function test_minimum_estimate_uses_lowest_text_and_reasoning_tokens(): void
    {
        $estimate = $this->pricingService()->estimate(400, '1536x1024', 'high', 2);

        $this->assertSame(85, $estimate->minimumUsage->inputTextTokens);
        $this->assertSame(1000, $estimate->minimumUsage->reasoningTokens);
        $this->assertSame('0.286225000000', $estimate->minimumProviderCost);
        $this->assertSame('0.357781250000', $estimate->minimumUserCost);
    }

    public function test_text_tokens_round_up_partial_tokens(): void
    {
        $estimate = $this->pricingService()->estimate(5, '1024x1024', 'low', 0);

        $this->assertSame(3, $estimate->maximumUsage->inputTextTokens);
        $this->assertSame(1, $estimate->minimumUsage->inputTextTokens);
    }

    public function test_actual_cost_is_calculated_from_usage_and_rates(): void
    {
        $usage = new TokenUsage(inputTextTokens: 12, inputImageTokens: 765, outputTokens: 6450, reasoningTokens: 3184);

        $providerCost = $this->pricingService()->providerCost($usage, self::RATES);

        $this->assertSame('0.305510000000', $providerCost);
        $this->assertSame('0.381887500000', $this->pricingService()->userCost($providerCost, '25'));
    }

    public function test_fractional_cent_costs_stay_exact(): void
    {
        $singleReasoningToken = new TokenUsage(inputTextTokens: 0, inputImageTokens: 0, outputTokens: 0, reasoningTokens: 1);

        $providerCost = $this->pricingService()->providerCost($singleReasoningToken, self::RATES);

        $this->assertSame('0.000012500000', $providerCost);
        $this->assertSame('0.000015625000', $this->pricingService()->userCost($providerCost, '25'));
    }

    public function test_markup_is_applied_once_to_the_provider_cost(): void
    {
        $userCost = $this->pricingService()->userCost('0.100000000', '25');

        $this->assertSame('0.125000000000', $userCost);
    }

    public function test_unsupported_size_and_quality_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pricingService()->estimate(10, '512x512', 'medium', 0);
    }

    public function test_pricing_is_fetched_once_and_cached(): void
    {
        $this->pricingService()->estimate(10, '1024x1024', 'medium', 0);
        $this->pricingService()->estimate(20, '1024x1024', 'high', 1);

        Http::assertSentCount(1);
    }
}
