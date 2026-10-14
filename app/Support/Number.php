<?php

namespace App\Support;

/**
 * Safe decimal arithmetic helpers for money and quantities.
 * Money is never handled as float; all values use BCMath string math.
 */
class Number
{
    /** Round to a currency-scale (2dp) value as a string. */
    public static function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }

    /** Round to a quantity-scale (4dp) value as a string. */
    public static function qty(string $value): string
    {
        return bcadd($value, '0', 4);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, 4);
    }

    public static function isNegative(string $value): bool
    {
        return bccomp($value, '0', 4) < 0;
    }

    /** Percentage of an amount: rate is a percentage (e.g. 15 => 15%). */
    public static function percentOf(string $amount, string $rate): string
    {
        return self::money(bcdiv(bcmul($amount, $rate, 6), '100', 6));
    }

    /** Normalize any numeric input into a clean decimal string. */
    public static function normalize($value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return (string) preg_replace('/[^0-9.\-]/', '', (string) $value);
    }
}
