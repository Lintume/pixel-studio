<?php

namespace App\Billing;

use App\Pixelforge\Pricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Turns token counts into money, following "Pricing and token usage" in the
 * Pixelforge docs. Everything is exact: BigDecimal while multiplying, integer
 * nanodollars once a sum is final.
 */
final class CostCalculator
{
    private const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * What Pixelforge charged for a succeeded generation: its token usage at the
     * rates it was created with (the rates in the generation object, not today's).
     *
     * @param  array<string, int>  $usage
     * @param  array<string, string>  $rates  dollars per 1M tokens
     */
    public function providerCost(array $usage, array $rates): int
    {
        $total = BigDecimal::zero();

        foreach (self::TOKEN_TYPES as $type) {
            $total = $total->plus($this->tokensCost($usage[$type] ?? 0, $rates[$type]));
        }

        return $this->toNanos($total);
    }

    /**
     * Provider cost plus the markup, rounded UP to the next nanodollar. 25% of
     * an odd number of nanodollars is a fraction of one; rounding up costs the
     * user at most $0.000000001 and never charges below cost.
     */
    public function userPrice(int $providerCostNanos): int
    {
        return BigDecimal::of($providerCostNanos)
            ->multipliedBy((string) config('services.pixelforge.markup'))
            ->toScale(0, RoundingMode::CEILING)
            ->toInt();
    }

    /**
     * The provider cost range before submitting. Output tokens and reference
     * images are exact; text tokens are only known within the published
     * variance, reasoning tokens within the range for the quality.
     *
     * @return array{min: int, max: int} provider cost in nanodollars
     */
    public function estimateProviderCost(Pricing $pricing, string $prompt, string $size, string $quality, int $referenceImages): array
    {
        $tokens = $pricing->tokens;
        $rates = $pricing->rates;

        $textEstimate = (int) ceil(mb_strlen($prompt) / $tokens['characters_per_text_token']);
        $variance = BigDecimal::of((string) $tokens['text_token_variance']);
        // The real count is within ±variance of the estimate. Round outwards, so
        // the range always contains it.
        $textMin = BigDecimal::of($textEstimate)->multipliedBy(BigDecimal::one()->minus($variance))->toScale(0, RoundingMode::FLOOR)->toInt();
        $textMax = BigDecimal::of($textEstimate)->multipliedBy(BigDecimal::one()->plus($variance))->toScale(0, RoundingMode::CEILING)->toInt();

        $fixed = $this->tokensCost($referenceImages * $tokens['per_reference_image'], $rates['input_image_tokens'])
            ->plus($this->tokensCost($tokens['output'][$quality][$size], $rates['output_tokens']));

        $reasoning = $tokens['reasoning'][$quality];

        return [
            'min' => $this->toNanos($fixed
                ->plus($this->tokensCost($textMin, $rates['input_text_tokens']))
                ->plus($this->tokensCost($reasoning['min'], $rates['reasoning_tokens']))),
            'max' => $this->toNanos($fixed
                ->plus($this->tokensCost($textMax, $rates['input_text_tokens']))
                ->plus($this->tokensCost($reasoning['max'], $rates['reasoning_tokens']))),
        ];
    }

    /** @return array{min: int, max: int}  what the user will pay, in nanodollars */
    public function estimateUserPrice(Pricing $pricing, string $prompt, string $size, string $quality, int $referenceImages): array
    {
        $provider = $this->estimateProviderCost($pricing, $prompt, $size, $quality, $referenceImages);

        return ['min' => $this->userPrice($provider['min']), 'max' => $this->userPrice($provider['max'])];
    }

    /** Cost in dollars of $count tokens at $ratePerMillion dollars per 1M tokens. */
    private function tokensCost(int $count, string $ratePerMillion): BigDecimal
    {
        return BigDecimal::of($ratePerMillion)->multipliedBy($count)->dividedBy(1_000_000, 9, RoundingMode::UNNECESSARY);
    }

    private function toNanos(BigDecimal $dollars): int
    {
        return $dollars->multipliedBy(Nanos::PER_DOLLAR)->toScale(0, RoundingMode::UNNECESSARY)->toInt();
    }
}
