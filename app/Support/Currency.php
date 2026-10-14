<?php

namespace App\Support;

use App\Models\Currency as CurrencyModel;

/**
 * Currency-aware formatting helpers shared across views and controllers.
 * Formatting uses the configured default currency so every monetary value in the
 * UI is displayed consistently. Values remain strings/decimals internally.
 */
class Currency
{
    protected static ?CurrencyModel $currency = null;

    public static function model(): CurrencyModel
    {
        if (static::$currency === null) {
            static::$currency = CurrencyModel::default() ?? new CurrencyModel([
                'symbol' => '$',
                'decimal_places' => 2,
                'symbol_position' => 'before',
                'thousand_separator' => ',',
                'decimal_separator' => '.',
            ]);
        }

        return static::$currency;
    }

    public static function symbol(): string
    {
        return static::model()->symbol;
    }

    public static function decimals(): int
    {
        return (int) static::model()->decimal_places;
    }

    /**
     * Format a numeric/string value as a currency string with symbol and
     * separators. Never uses float for precision-critical output.
     */
    public static function format($value, bool $withSymbol = true): string
    {
        $c = static::model();
        $value = Number::normalize($value);
        $decimals = (int) $c->decimal_places;

        $negative = Money::isNegative($value);
        $abs = ltrim($value, '-');

        [$int, $frac] = array_pad(explode('.', bcadd($abs, '0', $decimals), 2), 2, '0');
        $frac = substr($frac, 0, $decimals);

        $grouped = '';
        $len = strlen($int);
        for ($i = 0; $i < $len; $i++) {
            if ($i > 0 && ($len - $i) % 3 === 0) {
                $grouped .= $c->thousand_separator;
            }
            $grouped .= $int[$i];
        }

        $number = $decimals > 0 ? $grouped . $c->decimal_separator . $frac : $grouped;
        $sign = $negative ? '-' : '';

        if (! $withSymbol) {
            return $sign . $number;
        }

        return $c->symbol_position === 'before'
            ? $c->symbol . $sign . $number
            : $sign . $number . $c->symbol;
    }

    /** Format a plain number with configured decimal separator. */
    public static function number($value, int $decimals = 2): string
    {
        $c = static::model();
        $negative = Money::isNegative((string) $value);
        $abs = ltrim((string) $value, '-');
        [$int, $frac] = array_pad(explode('.', bcadd($abs, '0', $decimals), 2), 2, '0');
        $frac = substr($frac, 0, $decimals);

        $grouped = '';
        $len = strlen($int);
        for ($i = 0; $i < $len; $i++) {
            if ($i > 0 && ($len - $i) % 3 === 0) {
                $grouped .= $c->thousand_separator;
            }
            $grouped .= $int[$i];
        }

        return ($negative ? '-' : '') . ($decimals > 0 ? $grouped . $c->decimal_separator . $frac : $grouped);
    }

    public static function reset(): void
    {
        static::$currency = null;
    }
}
