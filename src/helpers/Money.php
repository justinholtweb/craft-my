<?php

namespace justinholtweb\my\helpers;

/**
 * Money arithmetic that has to agree with an accounting package.
 *
 * Everything here works in minor units (cents) internally. Adding up a column of floats and
 * comparing the result to another float is exactly the kind of thing that books an invoice a cent
 * away from the payment and leaves it open forever.
 */
abstract class Money
{
    /**
     * Round to cents the way a ledger does — half away from zero, so -0.005 is -0.01 and not -0.00.
     */
    public static function round(float $amount): float
    {
        return round($amount, 2);
    }

    /**
     * Minor units, as an integer.
     */
    public static function minor(float $amount): int
    {
        return (int)round($amount * 100);
    }

    /**
     * MYOB stores unit prices to six decimal places, so a per-unit figure derived by division does
     * not need rounding to cents — but it does need rounding, or `json_encode` writes seventeen
     * significant figures and the company file rejects the line.
     */
    public static function unit(float $amount): float
    {
        return round($amount, 6);
    }

    /**
     * Whether two amounts are the same money, within `$toleranceMinor` cents.
     */
    public static function equals(float $a, float $b, int $toleranceMinor = 0): bool
    {
        return abs(self::minor($a) - self::minor($b)) <= $toleranceMinor;
    }

    /**
     * `$a - $b` in cents. Positive means `$a` is the bigger number.
     */
    public static function diffMinor(float $a, float $b): int
    {
        return self::minor($a) - self::minor($b);
    }

    /**
     * Sum a column without accumulating float error.
     *
     * @param float[] $amounts
     */
    public static function sum(array $amounts): float
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += self::minor((float)$amount);
        }

        return $total / 100;
    }
}
