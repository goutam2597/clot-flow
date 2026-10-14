<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The POS formats money in JavaScript, separately from the PHP Formatter.
 *
 * That duplication is unavoidable — one runs in the browser, one on the server —
 * but it is also unguarded, and a bug in the browser copy is a bug on the screen
 * a cashier takes money against. The two mistakes that actually happened here
 * were both silent:
 *
 *   * grouping the thousands before splitting off the decimals, which renders
 *     ten units as "$1,0.00";
 *   * a toScaled/fromScaled round trip that cancels out, which renders a 5.52
 *     due as 552.00.
 *
 * Neither throws. Both are caught here by evaluating the real function against
 * known-good expectations.
 */
class PosMoneyTest extends TestCase
{
    /**
     * @var string|null
     */
    protected static $node;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Node is a test-time convenience here, not a build dependency: the
        // production stack is PHP and Blade only. Without it these cases skip
        // rather than fail, so the suite still runs on a machine that has only
        // a PHP toolchain.
        $path = self::locateNode();

        if ($path !== null) {
            self::$node = escapeshellarg($path);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$node = null;

        parent::tearDownAfterClass();
    }

    protected function skipWithoutNode(): void
    {
        if (self::$node === null) {
            $this->markTestSkipped('node is not available to evaluate pos.js');
        }
    }

    /** Run the real money() from public/js/pos.js against a set of inputs. */
    protected function format(array $amounts, array $currency = []): array
    {
        $this->skipWithoutNode();

        $js = file_get_contents(public_path('js/pos.js'));

        if (! preg_match('/function money\(value\) \{.*?\n    \}/s', $js, $m)) {
            $this->fail('money() could not be found in public/js/pos.js.');
        }

        $currency = array_merge([
            'symbol' => '$',
            'position' => 'before',
            'decimals' => 2,
            'thousand' => ',',
            'decimal' => '.',
        ], $currency);

        $script = <<<'JS'
        var boot = { currency: __CURRENCY__ };
        var App = { toast: function () {} };
        __MONEY__

        // Mirror of the two helpers money() depends on, copied rather than
        // imported so the test exercises money() and not the import graph.
        function toScaled(value, decimals) {
            var n = parseFloat(value);
            if (isNaN(n)) { return 0; }
            return Math.round(n * Math.pow(10, decimals));
        }

        console.log(JSON.stringify(__AMOUNTS__.map(function (a) { return money(a); })));
        JS;

        $script = str_replace(
            ['__CURRENCY__', '__MONEY__', '__AMOUNTS__'],
            [json_encode($currency), $m[0], json_encode($amounts)],
            $script
        );

        $file = tempnam(sys_get_temp_dir(), 'posmoney').'.js';
        file_put_contents($file, $script);

        $output = shell_exec(self::$node.' '.escapeshellarg($file).' 2>&1');
        unlink($file);

        $decoded = json_decode(trim((string) $output), true);

        $this->assertIsArray(
            $decoded,
            "money() did not evaluate cleanly:\n".$output
        );

        return $decoded;
    }

    public function test_it_formats_whole_amounts_with_thousands_separators(): void
    {
        $this->assertSame(
            ['$0.00', '$1.05', '$10.00', '$100.00', '$1,000.00', '$12,345.67'],
            $this->format([0, 1.05, 10, 100, 1000, 12345.67])
        );
    }

    public function test_the_separator_never_lands_inside_the_decimals(): void
    {
        // The regression: grouping first put a comma into the fraction, so ten
        // units rendered as "$1,0.00" and a cashier could charge that.
        $formatted = $this->format([10, 20, 50, 100, 500, 1000]);

        foreach ($formatted as $value) {
            $this->assertDoesNotMatchRegularExpression(
                '/,\d\.\d{2}$/',
                $value,
                "Separator landed inside the decimals: {$value}"
            );
        }
    }

    public function test_it_formats_sub_unit_amounts_without_losing_a_digit(): void
    {
        // Zero-padding exists so a value below the currency's precision does not
        // slice into "$.7".
        $this->assertSame(['$0.07', '$0.01', '$0.99'], $this->format([0.07, 0.01, 0.99]));
    }

    public function test_it_formats_negative_amounts(): void
    {
        $this->assertSame(['-$5.00', '-$1,000.00'], $this->format([-5, -1000]));
    }

    public function test_it_honours_a_zero_decimal_currency(): void
    {
        // No fraction at all, so no decimal separator and no trailing zeros -
        // and the symbol trails the number, which is how these are written.
        $this->assertSame(
            ['0JPY', '1,000JPY'],
            $this->format([0, 1000], [
                'symbol' => 'JPY',
                'position' => 'after',
                'decimals' => 0,
                'thousand' => ',',
                'decimal' => '.',
            ])
        );
    }

    protected static function locateNode(): ?string
    {
        foreach (['node'] as $binary) {
            $path = @exec('command -v '.escapeshellarg($binary).' 2>/dev/null');

            if (is_string($path) && $path !== '' && @is_executable($path)) {
                return $path;
            }
        }

        // Windows.
        foreach (['node.exe', 'node'] as $binary) {
            $found = @exec('where '.escapeshellarg($binary).' 2>nul');

            if (is_string($found) && trim($found) !== '') {
                $first = trim(explode("\n", trim($found))[0]);

                if (@is_executable($first)) {
                    return $first;
                }
            }
        }

        return null;
    }
}
