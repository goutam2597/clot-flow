<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Button styles live in exactly one file.
 *
 * components.css once re-declared every .btn-* variant. It loads after
 * buttons.css, so its definitions silently won, and three of them put back
 * regressions that had already been fixed in buttons.css:
 *
 *   .btn-secondary     back to --color-secondary, which is near-white in dark
 *                      mode - white text on a near-white fill;
 *   .btn-outline       transparent instead of a surface fill, so it vanishes
 *                      against the page;
 *   .btn-danger-ghost  a solid pink slab on every table row, which is the
 *                      opposite of what "ghost" means;
 *
 * plus hard-coded #fff on three variants, which is how a theme stops being able
 * to invert them.
 *
 * Nothing about this fails a build. It fails silently, on every page, which is
 * exactly why it needs an assertion.
 */
class ButtonStyleOwnershipTest extends TestCase
{
    /** Only components.css may define these; buttons.css owns the variants. */
    protected const BUTTON_OWNER = 'buttons.css';

    protected const NOT_OWNED = [
        // Structural rules that components.css is the only definition of.
        'btn-icon',
        'btn-label',
        'spinner',
    ];

    public function test_only_one_file_defines_the_button_variants(): void
    {
        $offenders = [];

        foreach (File::files(public_path('css')) as $file) {
            if ($file->getFilename() === self::BUTTON_OWNER) {
                continue;
            }

            $css = File::get($file->getPathname());
            $css = preg_replace('#/\*.*?\*/#s', '', $css);

            // Only top-level variant rules, not rules that merely mention the
            // class inside a compound selector.
            preg_match_all('/^\s*\.(btn-(?:primary|secondary|outline|ghost|danger|danger-ghost|success|block))\b[^{]*\{/m', $css, $matches);

            foreach (array_unique($matches[1]) as $variant) {
                if (in_array($variant, self::NOT_OWNED, true)) {
                    continue;
                }

                $offenders[] = $file->getFilename().' defines .'.$variant;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Button variants are defined outside ".self::BUTTON_OWNER.", and the later stylesheet "
            ."silently wins:\n  ".implode("\n  ", $offenders)
        );
    }

    public function test_the_button_owner_declares_every_variant_the_component_can_render(): void
    {
        $css = File::get(public_path('css/'.self::BUTTON_OWNER));

        $component = File::get(resource_path('views/components/ui/button.blade.php'));

        preg_match_all("/'([a-z-]+)'\s*,\s*\/\/ primary/", $component, $documented);

        foreach ($documented[1] as $variant) {
            if ($variant === 'link') {
                continue;
            }

            $this->assertStringContainsString(
                '.btn-'.$variant,
                $css,
                "x-ui.button documents the \"{$variant}\" variant but ".self::BUTTON_OWNER." has no rule for it."
            );
        }
    }

    public function test_no_button_variant_hard_codes_its_text_colour(): void
    {
        $css = File::get(public_path('css/'.self::BUTTON_OWNER));

        // A literal #fff cannot be inverted, so the moment a theme needs a
        // different on-fill colour this stops working and nothing says so.
        $this->assertDoesNotMatchRegularExpression(
            '/\.btn-(primary|secondary|outline|ghost|danger|success)[^{]*\{[^}]*color:\s*#fff/s',
            $css,
            'Button text colour must come from a token so the theme can invert it.'
        );
    }

    public function test_a_ghost_button_is_transparent_until_hovered(): void
    {
        $css = File::get(public_path('css/'.self::BUTTON_OWNER));

        // The regression: a filled slab on every row of every table, which
        // reads as the primary action of the row rather than as a destructive
        // one-off.
        preg_match('/\.btn-danger-ghost\s*\{([^}]*)\}/', $css, $rule);

        $this->assertNotEmpty($rule, '.btn-danger-ghost must exist.');

        $this->assertStringContainsString(
            'transparent',
            $rule[1],
            '.btn-danger-ghost must be transparent until hovered.'
        );
    }
}
