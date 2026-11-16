<?php

namespace App\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Money is stored as integer nanodollars: $1 = 1_000_000_000.
 *
 * Pixelforge prices one token in millionths of a dollar and reports costs as
 * exact decimals with nine places, so a nanodollar is the smallest amount it can
 * ever bill. Integers keep every sum exact; floats would drift.
 */
final class Nanos
{
    public const PER_DOLLAR = 1_000_000_000;

    /** "0.046125000" → 46125000. Pixelforge never sends more than nine decimals. */
    public static function fromUsd(string $usd): int
    {
        return BigDecimal::of($usd)
            ->multipliedBy(self::PER_DOLLAR)
            ->toScale(0, RoundingMode::UNNECESSARY)
            ->toInt();
    }

    /** 46125000 → "0.046125000", the same shape Pixelforge reports. */
    public static function toUsd(int $nanos): string
    {
        return (string) BigDecimal::ofUnscaledValue($nanos, 9);
    }
}
