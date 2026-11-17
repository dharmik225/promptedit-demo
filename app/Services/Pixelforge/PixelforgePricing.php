<?php

namespace App\Services\Pixelforge;

use UnexpectedValueException;

/**
 * The token rates and token-count rules published by GET /v1/pricing.
 */
final readonly class PixelforgePricing
{
    public const RATE_KEYS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * @param  array<string, string>  $rates
     * @param  array<string, array<string, int>>  $outputTokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens
     */
    public function __construct(
        public array $rates,
        public int $charactersPerTextToken,
        public string $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /** @param array<string, mixed> $pricing */
    public static function fromArray(array $pricing): self
    {
        $tokens = $pricing['tokens'] ?? null;

        if (! is_array($tokens)) {
            throw new UnexpectedValueException('Pixelforge pricing is missing token rules.');
        }

        $charactersPerTextToken = (int) ($tokens['characters_per_text_token'] ?? 0);

        if ($charactersPerTextToken < 1) {
            throw new UnexpectedValueException('Pixelforge pricing has an invalid characters_per_text_token.');
        }

        return new self(
            rates: self::parseRates($pricing['rates'] ?? null),
            charactersPerTextToken: $charactersPerTextToken,
            textTokenVariance: self::decimalString($tokens['text_token_variance'] ?? null, 'text_token_variance'),
            tokensPerReferenceImage: (int) ($tokens['per_reference_image'] ?? 0),
            outputTokens: self::parseOutputTokens($tokens['output'] ?? null),
            reasoningTokens: self::parseReasoningTokens($tokens['reasoning'] ?? null),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function parseRates(mixed $rates): array
    {
        if (! is_array($rates)) {
            throw new UnexpectedValueException('Pixelforge rates are missing.');
        }

        $parsedRates = [];

        foreach (self::RATE_KEYS as $rateKey) {
            $parsedRates[$rateKey] = self::decimalString($rates[$rateKey] ?? null, "rates.{$rateKey}");
        }

        return $parsedRates;
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /** @return list<string> */
    public function sizes(): array
    {
        $sizes = [];

        foreach ($this->outputTokens as $tokensBySize) {
            $sizes = [...$sizes, ...array_keys($tokensBySize)];
        }

        return array_values(array_unique($sizes));
    }

    public function supports(string $size, string $quality): bool
    {
        if (! isset($this->outputTokens[$quality][$size])) {
            return false;
        }

        return isset($this->reasoningTokens[$quality]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates,
            'tokens' => [
                'characters_per_text_token' => $this->charactersPerTextToken,
                'text_token_variance' => $this->textTokenVariance,
                'per_reference_image' => $this->tokensPerReferenceImage,
                'output' => $this->outputTokens,
                'reasoning' => $this->reasoningTokens,
            ],
        ];
    }

    /**
     * Pixelforge sends rates as decimal strings; the variance arrives as a JSON
     * number, which PHP's shortest round-trip conversion turns back into "0.15".
     */
    private static function decimalString(mixed $value, string $field): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            throw new UnexpectedValueException("Pixelforge pricing field [{$field}] is not a decimal.");
        }

        return $value;
    }

    /** @return array<string, array<string, int>> */
    private static function parseOutputTokens(mixed $output): array
    {
        if (! is_array($output) || $output === []) {
            throw new UnexpectedValueException('Pixelforge pricing is missing output tokens.');
        }

        $outputTokens = [];

        foreach ($output as $quality => $tokensBySize) {
            foreach ((array) $tokensBySize as $size => $tokens) {
                $outputTokens[(string) $quality][(string) $size] = (int) $tokens;
            }
        }

        return $outputTokens;
    }

    /** @return array<string, array{min: int, max: int}> */
    private static function parseReasoningTokens(mixed $reasoning): array
    {
        if (! is_array($reasoning) || $reasoning === []) {
            throw new UnexpectedValueException('Pixelforge pricing is missing reasoning tokens.');
        }

        $reasoningTokens = [];

        foreach ($reasoning as $quality => $range) {
            $reasoningTokens[(string) $quality] = [
                'min' => (int) ($range['min'] ?? 0),
                'max' => (int) ($range['max'] ?? 0),
            ];
        }

        return $reasoningTokens;
    }
}
