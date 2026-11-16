<?php

namespace Tests\Feature;

use App\Billing\CostCalculator;
use App\Pixelforge\Pricing;
use Tests\Support\FakesPixelforge;
use Tests\TestCase;

class CostCalculatorTest extends TestCase
{
    use FakesPixelforge;

    public function test_the_docs_worked_example_of_the_most_a_generation_can_cost(): void
    {
        $estimate = app(CostCalculator::class)->estimateProviderCost(
            Pricing::fromArray($this->pricing()),
            str_repeat('a', 400),
            '1536x1024',
            'high',
            2,
        );

        $this->assertSame(373_875_000, $estimate['max']); // $0.373875000
    }

    public function test_the_estimate_range_always_contains_the_real_cost(): void
    {
        $calculator = app(CostCalculator::class);
        // 10 chars → 3 estimated text tokens → 2..4 real ones (±15%, rounded outwards).
        $estimate = $calculator->estimateProviderCost(Pricing::fromArray($this->pricing()), 'abcdefghij', '1024x1024', 'low', 0);

        $cheapest = $calculator->providerCost(['input_text_tokens' => 2, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 0], self::RATES);
        $dearest = $calculator->providerCost(['input_text_tokens' => 4, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 500], self::RATES);

        $this->assertSame($cheapest, $estimate['min']);
        $this->assertSame($dearest, $estimate['max']);
    }

    public function test_text_tokens_count_characters_not_bytes(): void
    {
        $calculator = app(CostCalculator::class);
        $pricing = Pricing::fromArray($this->pricing());

        // Eight Cyrillic letters are 16 bytes but 8 characters, so 2 tokens.
        $this->assertSame(
            $calculator->estimateProviderCost($pricing, 'abcdefgh', '1024x1024', 'low', 0),
            $calculator->estimateProviderCost($pricing, 'абвгґдеє', '1024x1024', 'low', 0),
        );
    }

    public function test_actual_cost_is_usage_times_the_generations_own_rates(): void
    {
        // 12×$5 + 765×$10 + 6450×$40 + 3184×$12.50 per 1M = $0.305510
        $this->assertSame(305_510_000, app(CostCalculator::class)->providerCost(self::USAGE, self::RATES));
    }

    public function test_the_user_pays_cost_plus_25_percent_rounded_up_to_the_nanodollar(): void
    {
        $calculator = app(CostCalculator::class);

        $this->assertSame(381_887_500, $calculator->userPrice(305_510_000));
        $this->assertSame(2, $calculator->userPrice(1)); // 1.25 → 2, never below cost
        $this->assertSame(0, $calculator->userPrice(0));
    }
}
