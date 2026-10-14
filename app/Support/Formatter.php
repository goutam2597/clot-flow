<?php

namespace App\Support;

use App\Models\Currency;
use App\Models\Setting;

/**
 * Presentation helpers: currency formatting driven by the active Currency row,
 * date formatting, and business settings access. Kept framework-light so it can
 * be used from Blade, controllers and tests.
 */
class Formatter
{
    protected static ?Currency $currency = null;

    protected static bool $currencyLoaded = false;

    /**
     * Active currency (default row), cached for the request.
     */
    public static function currency(): Currency
    {
        if (! self::$currencyLoaded) {
            self::$currency = Currency::default();
            self::$currencyLoaded = true;
        }

        return self::$currency ?? self::fallbackCurrency();
    }

    protected static function fallbackCurrency(): Currency
    {
        $c = new Currency([
            'code' => 'USD', 'symbol' => '$', 'decimal_places' => 2,
            'symbol_position' => 'before', 'thousand_separator' => ',',
            'decimal_separator' => '.',
        ]);

        return $c;
    }

    /**
     * Format a decimal string as currency using the active currency's rules.
     */
    public static function money($value, bool $withSymbol = true, int $decimals = null): string
    {
        $currency = self::currency();
        $decimals ??= (int) $currency->decimal_places;
        $value = (string) ($value ?? '0');

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');

        // Normalize to a plain decimal string with the right precision.
        $formatted = number_format(
            (float) $value,
            $decimals,
            $currency->decimal_separator ?: '.',
            $currency->thousand_separator !== '' ? (string) $currency->thousand_separator : ''
        );

        $body = ($negative ? '-' : '') . $formatted;

        if (! $withSymbol) {
            return $body;
        }

        return $currency->symbol_position === 'after'
            ? $body . $currency->symbol
            : $currency->symbol . $body;
    }

    /**
     * Format a plain quantity/decimal number (no currency symbol).
     */
    public static function number($value, int $decimals = 2): string
    {
        $currency = self::currency();

        return number_format(
            (float) ($value ?? 0),
            $decimals,
            $currency->decimal_separator ?: '.',
            $currency->thousand_separator !== '' ? (string) $currency->thousand_separator : ''
        );
    }

    /**
     * Format a date using the active locale.
     */
    public static function date($value, string $format = 'M j, Y'): string
    {
        if (! $value) {
            return '—';
        }

        return \Carbon\Carbon::parse($value)->translatedFormat($format);
    }

    public static function dateTime($value, string $format = 'M j, Y g:i A'): string
    {
        if (! $value) {
            return '—';
        }

        return \Carbon\Carbon::parse($value)->translatedFormat($format);
    }

    /**
     * Read a business setting by key.
     */
    public static function setting(string $key, $default = null, string $group = 'general')
    {
        return Setting::get($key, $default, $group);
    }
}
