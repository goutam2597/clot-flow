<?php

namespace Tests\Feature;

use App\Support\Barcode;
use Tests\TestCase;

/**
 * Code 128 encoder.
 *
 * A barcode that renders is not a barcode that scans. The only way to know the
 * output is right is to decode it again with an independent implementation and
 * check the value survives the round trip, which is what most of these do.
 *
 * The decoder here is written from the symbology definition rather than by
 * inverting the encoder, because an inverse of the encoder proves only that the
 * encoder is self-consistent — and the two mistakes that actually happen
 * (a wrong checksum weight, bars and spaces swapped) are invisible to it.
 */
class BarcodeTest extends TestCase
{
    /**
     * Code 128 element patterns, narrow-to-wide. Written out independently of
     * the encoder's table.
     *
     * @var array<int, string>
     */
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

    public function test_it_round_trips_an_invoice_number(): void
    {
        $this->assertSame(
            'INV-202610-00096',
            $this->decode(Barcode::code128('INV-202610-00096'))
        );
    }

    public function test_it_round_trips_the_whole_printable_ascii_range(): void
    {
        // Every character Code 128 set B covers, in one payload. A character
        // with a wrong pattern or a wrong offset shows up here and nowhere
        // else.
        $value = '';
        for ($c = 32; $c <= 126; $c++) {
            $value .= chr($c);
        }

        $this->assertSame($value, $this->decode(Barcode::code128($value)));
    }

    public function test_a_scan_decodes_to_the_same_invoice_number(): void
    {
        $references = [
            'INV-202610-00096',
            'INV-202610-10000',
            'INV-202610-00001',
            'POS/2026/11/0007',
            'A1',
        ];

        foreach ($references as $reference) {
            $this->assertSame(
                $reference,
                $this->decode(Barcode::code128($reference)),
                "Round trip failed for {$reference}."
            );
        }
    }

    public function test_the_checksum_is_weighted_from_one(): void
    {
        // Start B (104), then 'A' (33) and 'B' (34). The weight runs 1, 2, 3
        // across the whole symbol including the start code, so the data
        // characters weigh 1 and 2.
        //
        //   (104 + 33*1 + 34*2) % 103 = 205 % 103 = 102
        $expected = (104 + 33 * 1 + 34 * 2) % 103;

        $this->assertSame(102, $expected, 'The fixture drifted from the spec.');

        $codes = $this->symbols(Barcode::code128('AB'));

        $this->assertSame(104, $codes[0], 'Start code B.');
        $this->assertSame(33, $codes[1], 'A.');
        $this->assertSame(34, $codes[2], 'B.');
        $this->assertSame($expected, $codes[3], 'Checksum.');
        $this->assertSame(106, $codes[4], 'Stop pattern.');
    }

    public function test_it_starts_and_stops_correctly(): void
    {
        $codes = $this->symbols(Barcode::code128('X'));

        $this->assertSame(104, $codes[0], 'A reader locks onto the start code; anything else is not scanned.');
        $this->assertSame(106, end($codes), 'The stop pattern is 13 modules, not 11.');
    }

    public function test_it_drops_characters_the_symbology_cannot_carry(): void
    {
        // Code set B is ASCII 32-127. Silently encoding a different character
        // produces a barcode that scans to the wrong invoice, which is worse
        // than one that encodes nothing.
        $this->assertSame('INV96', $this->decode(Barcode::code128("INV\n96")));
    }

    public function test_an_empty_value_still_produces_a_scannable_symbol(): void
    {
        // The placeholder is a digit rather than nothing: a zero-width barcode
        // is a blank strip on the roll and a scanner error at the till.
        $codes = $this->symbols(Barcode::code128(''));

        $this->assertSame(104, $codes[0]);
        $this->assertSame(106, end($codes));
        $this->assertSame('0', $this->decode(Barcode::code128('')));
    }

    public function test_the_svg_has_a_path_and_no_unbounded_growth(): void
    {
        $svg = Barcode::svg('INV-202610-00096');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<path', $svg);

        // One path, not one rect per bar: ~211 modules as elements is a DOM
        // that costs more to lay out than the entire receipt around it.
        $this->assertSame(1, substr_count($svg, '<path'));
        $this->assertStringNotContainsString('<rect', $svg);

        $this->assertStringContainsString('aria-label="INV-202610-00096"', $svg);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Read a module pattern back into its Code 128 values.
     *
     * @return array<int, int>
     */
    private function symbols(string $modules): array
    {
        // Each symbol starts with a bar, and the widths cycle through 2,3,4.
        // Walk the bar/space runs to recover the widths, then regroup them.
        $widths = [];
        $current = 0;
        $isBar = $modules[0] === '1';

        foreach (str_split($modules) as $char) {
            $charIsBar = $char === '1';

            if ($charIsBar === $isBar) {
                $current++;
                continue;
            }

            $widths[] = $current;
            $current = 1;
            $isBar = $charIsBar;
        }

        $widths[] = $current;

        $chunks = array_chunk($widths, 6);
        $count = count($chunks);

        // The stop pattern is seven elements, not six: a normal symbol ends on
        // a space and the stop ends on a final bar. Chunking on six leaves a
        // one-element tail that belongs to the symbol before it.
        if ($count > 1 && count($chunks[$count - 1]) < 6) {
            $tail = array_pop($chunks);
            $chunks[count($chunks) - 1] = array_merge($chunks[count($chunks) - 1], $tail);
        }

        $codes = [];

        foreach ($chunks as $chunk) {
            $found = array_search(implode('', $chunk), self::PATTERNS, true);

            $this->assertNotFalse($found, 'Unreadable Code 128 symbol: '.implode('', $chunk));

            $codes[] = $found;
        }

        return $codes;
    }

    /** Decode a module pattern back into the string it encodes. */
    private function decode(string $modules): string
    {
        $codes = $this->symbols($modules);

        // Verify the framing a scanner would insist on before reading data.
        $this->assertSame(104, $codes[0], 'Missing start code B.');
        $this->assertSame(106, end($codes), 'Missing stop pattern.');

        $data = array_slice($codes, 1, -2);
        $checksum = $codes[count($codes) - 2];

        $sum = $codes[0];
        foreach ($data as $i => $code) {
            $sum += $code * ($i + 1);
        }

        $this->assertSame($sum % 103, $checksum, 'Checksum does not verify.');

        $out = '';
        foreach ($data as $code) {
            $out .= chr($code + 32);
        }

        return $out;
    }
}
