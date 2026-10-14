<?php

namespace App\Support;

/**
 * Code 128 barcode encoder.
 *
 * A thermal till receipt carries the invoice number as a machine-readable code
 * so the customer, the returns desk and the next cashier can all read it back
 * without anyone typing digits off a 58mm strip of paper.
 *
 * Code 128 because it is what every handheld scanner in a shop actually reads,
 * and because it encodes the full ASCII range — an invoice reference that ever
 * grows a letter has to keep working.
 *
 * The output is a bar/space pattern, not an image: no canvas, no library, no
 * server-side rendering, and it prints crisply at whatever DPI the roll printer
 * happens to be set to.
 */
final class Barcode
{
    /** Bar/space widths per Code 128 value, in modules. Six elements each. */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** Start code B (the alphanumeric subset), stop, and the modulo-103 checksum. */
    private const START_B = 104;
    private const STOP = 106;

    /**
     * The module pattern for a value, as a string of 0s and 1s.
     *
     * Widths are written narrow-wide because that is how the symbology defines
     * them; a barcode that draws every bar the same width is not a barcode, it
     * is a decoration that no scanner will ever read.
     */
    public static function code128(string $value): string
    {
        $value = self::sanitise($value);

        if ($value === '') {
            $value = '0';
        }

        $codes = [self::START_B];

        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $codes[] = ord($value[$i]) - 32;
        }

        $codes[] = self::checksum($codes);
        $codes[] = self::STOP;

        return self::toModuleString($codes);
    }

    /**
     * Render a value as an SVG barcode.
     *
     * Drawn with one filled path rather than a rect per bar: a 13-digit invoice
     * is 15*11 + 13 = ~178 modules, which as separate elements is a DOM that
     * costs more to lay out than the entire receipt around it.
     */
    public static function svg(string $value, int $height = 48): string
    {
        $modules = self::code128($value);
        $width = strlen($modules);

        $path = '';
        $run = 0;
        $runIsBar = $modules[0] === '1';

        for ($i = 0; $i <= $width; $i++) {
            $isBar = $i < $width && $modules[$i] === '1';

            if ($i === $width || $isBar !== $runIsBar) {
                $path .= sprintf(
                    'M%d 0h%dv%dh-%dz',
                    $run,
                    $run,
                    $height,
                    $run,
                    $height
                );

                $run = 0;
                $runIsBar = $isBar;
            }

            $run++;
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" '.
            'shape-rendering="crispEdges" preserveAspectRatio="none" role="img" aria-label="%s">'.
            '<path fill="currentColor" d="%s"/></svg>',
            $width,
            $height,
            $width,
            $height,
            htmlspecialchars($value, ENT_QUOTES),
            $path
        );
    }

    /**
     * Modulo-103 weighted checksum.
     *
     * The weight is the character position starting at 1 for the start code, so
     * the first data character is weighted 1 and not 2. Getting that off by one
     * produces a barcode that scans on a good day.
     */
    protected static function checksum(array $codes): int
    {
        $sum = self::START_B;

        for ($i = 1, $weight = 1; $i < count($codes); $i++, $weight++) {
            $sum += $codes[$i] * $weight;
        }

        return $sum % 103;
    }

    /** @return array<int, string> one six-element pattern per code */
    protected static function toModuleString(array $codes): string
    {
        $out = '';

        foreach ($codes as $code) {
            $pattern = self::PATTERNS[$code];

            for ($i = 0, $width = 2; $i < strlen($pattern); $i++, $width++) {
                // Even positions are bars, odd positions are spaces. Six
                // elements per symbol, three of each.
                $isBar = ($i % 2) === 0;
                $out .= str_repeat($isBar ? '1' : '0', (int) $pattern[$i]);
            }
        }

        return $out;
    }

    /**
     * Code set B covers ASCII 32-127. Anything else is replaced rather than
     * silently truncated, because a barcode that encodes the wrong invoice
     * number is worse than one that refuses to encode.
     */
    protected static function sanitise(string $value): string
    {
        return preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
    }
}
