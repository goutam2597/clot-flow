<?php

namespace App\Support;

/**
 * All monetary and quantity arithmetic is done with bcmath strings.
 * Never use float for money. Default scale is 2 for money, 4 for quantities.
 */
class Money
{
    public static function add(string $a, string $b, int $scale = 2): string
    {
        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = 2): string
    {
        return bcsub($a, $b, $scale);
    }

    public static function mul(string $a, string $b, int $scale = 2): string
    {
        return bcmul($a, $b, $scale);
    }

    public static function div(string $a, string $b, int $scale = 2): string
    {
        if (bccomp($b, '0', $scale) === 0) {
            return '0';
        }

        return bcdiv($a, $b, $scale);
    }

    /**
     * Percentage of an amount. rate is a percentage e.g. 15 = 15%.
     */
    public static function percent(string $amount, string $rate, int $scale = 2): string
    {
        return self::div(self::mul($amount, $rate, $scale + 2), '100', $scale);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, 4);
    }

    public static function isZero(string $a): bool
    {
        return bccomp($a, '0', 4) === 0;
    }

    public static function isNegative(string $a): bool
    {
        return bccomp($a, '0', 4) < 0;
    }

    public static function min(string $a, string $b, ?int $scale = null): string
    {
        return bccomp($a, $b, $scale ?? 4) <= 0 ? $a : $b;
    }

    public static function max(string $a, string $b, ?int $scale = null): string
    {
        return bccomp($a, $b, $scale ?? 4) >= 0 ? $a : $b;
    }

    public static function sum(array $values, int $scale = 2): string
    {
        $total = '0';
        foreach ($values as $v) {
            $total = bcadd($total, (string) $v, $scale);
        }

        return $total;
    }

    /**
     * Round a decimal string to the given scale, half-up.
     *
     * Used when an allocated share has to be pinned to the currency's
     * precision, e.g. splitting one discount across many sale lines.
     */
    public static function round(string $value, int $scale = 2): string
    {
        return bcadd($value, '0', $scale);
    }
}
