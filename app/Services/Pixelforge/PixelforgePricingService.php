<?php

namespace App\Services\Pixelforge;

use App\Services\Generation\TokenUsage;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use InvalidArgumentException;

/**
 * All money is handled as decimal strings with bcmath. Pixelforge costs are
 * exact to nine decimal places and the markup can add a few more, so twelve
 * decimal places keep every amount exact without rounding.
 */
class PixelforgePricingService
{
    public const SCALE = 12;

    private const TOKENS_PER_RATE_UNIT = '1000000';

    private const PRICING_CACHE_KEY = 'pixelforge:pricing';

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly CacheRepository $cache,
        private readonly int $cacheTtlSeconds,
        private readonly string $markupPercentage,
    ) {}

    public function pricing(): PixelforgePricing
    {
        $pricing = $this->cache->remember(
            self::PRICING_CACHE_KEY,
            $this->cacheTtlSeconds,
            fn (): array => PixelforgePricing::fromArray($this->client->pricing())->toArray(),
        );

        return PixelforgePricing::fromArray($pricing);
    }

    public function markupPercentage(): string
    {
        return $this->markupPercentage;
    }

    public function estimate(
        int $promptCharacters,
        string $size,
        string $quality,
        int $referenceImageCount,
    ): CostEstimate {
        $pricing = $this->pricing();

        if (! $pricing->supports($size, $quality)) {
            throw new InvalidArgumentException("Pixelforge does not support size [{$size}] with quality [{$quality}].");
        }

        $expectedTextTokens = $this->ceilDivide($promptCharacters, $pricing->charactersPerTextToken);
        $inputImageTokens = $referenceImageCount * $pricing->tokensPerReferenceImage;
        $outputTokens = $pricing->outputTokens[$quality][$size];
        $reasoningRange = $pricing->reasoningTokens[$quality];

        $minimumUsage = new TokenUsage(
            inputTextTokens: $this->floorDecimal(bcmul((string) $expectedTextTokens, bcsub('1', $pricing->textTokenVariance, self::SCALE), self::SCALE)),
            inputImageTokens: $inputImageTokens,
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningRange['min'],
        );

        $maximumUsage = new TokenUsage(
            inputTextTokens: $this->ceilDecimal(bcmul((string) $expectedTextTokens, bcadd('1', $pricing->textTokenVariance, self::SCALE), self::SCALE)),
            inputImageTokens: $inputImageTokens,
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningRange['max'],
        );

        $minimumProviderCost = $this->providerCost($minimumUsage, $pricing->rates);
        $maximumProviderCost = $this->providerCost($maximumUsage, $pricing->rates);

        return new CostEstimate(
            minimumUsage: $minimumUsage,
            maximumUsage: $maximumUsage,
            minimumProviderCost: $minimumProviderCost,
            maximumProviderCost: $maximumProviderCost,
            minimumUserCost: $this->userCost($minimumProviderCost, $this->markupPercentage),
            maximumUserCost: $this->userCost($maximumProviderCost, $this->markupPercentage),
            markupPercentage: $this->markupPercentage,
            rates: $pricing->rates,
        );
    }

    /**
     * Rates are US dollars per one million tokens of each type.
     *
     * @param  array<string, string>  $rates
     */
    public function providerCost(TokenUsage $usage, array $rates): string
    {
        $rates = PixelforgePricing::parseRates($rates);
        $cost = '0';

        foreach ($usage->toArray() as $tokenType => $tokenCount) {
            $tokenCost = bcdiv(bcmul((string) $tokenCount, $rates[$tokenType], self::SCALE), self::TOKENS_PER_RATE_UNIT, self::SCALE);
            $cost = bcadd($cost, $tokenCost, self::SCALE);
        }

        return $cost;
    }

    public function userCost(string $providerCost, string $markupPercentage): string
    {
        $markupMultiplier = bcadd('1', bcdiv($markupPercentage, '100', self::SCALE), self::SCALE);

        return bcmul($providerCost, $markupMultiplier, self::SCALE);
    }

    public function zeroCost(): string
    {
        return bcadd('0', '0', self::SCALE);
    }

    private function ceilDivide(int $dividend, int $divisor): int
    {
        return intdiv($dividend + $divisor - 1, $divisor);
    }

    private function floorDecimal(string $value): int
    {
        return (int) bcadd($value, '0', 0);
    }

    private function ceilDecimal(string $value): int
    {
        $truncated = bcadd($value, '0', 0);

        if (bccomp($value, $truncated, self::SCALE) === 0) {
            return (int) $truncated;
        }

        return (int) $truncated + 1;
    }
}
