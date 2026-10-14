<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Field bars: labelled fields plus the button that submits them, on one row.
 *
 * Five report and accounting pages share this shape, and every one of them
 * was misaligned: the button hung 16px below the input on four of them (the
 * `.form-group` bottom margin shifts end-alignment) and 56px below on
 * trial-balance (a `:hint` inside the field on top of the margin).
 *
 * Both halves are structural, so both are asserted structurally:
 *
 *   1. the row is one shared `.field-bar` class, never a copy of an inline
 *      style - five copies is how the sixth grew a hint;
 *   2. no hint renders inside the bar - a hint makes its column taller and an
 *      end-aligned row drops the button to the hint's level;
 *   3. the class zeroes the field margin - without the reset the button still
 *      hangs a margin below the input, hint or no hint.
 *
 * The class is `.field-bar` and not `.filter-bar` on purpose: components.css
 * already defines a `.filter-bar` for table toolbars, and two definitions of
 * one class is a race the later file always wins.
 */
class FieldBarTest extends TestCase
{
    use RefreshDatabase;

    /** Pages whose filter is fields plus an inline submit button. */
    protected function filterPages(): array
    {
        return [
            '/accounting/trial-balance',
            '/accounting/balance-sheet',
            '/accounting/profit-loss',
            '/reports/profit-loss',
            '/reports/sales',
        ];
    }

    public function test_every_filter_row_uses_the_shared_class(): void
    {
        $owner = $this->bootBusiness()->owner;

        foreach ($this->filterPages() as $path) {
            $html = $this->actingAs($owner)->get($path)->assertOk()->getContent();

            $this->assertStringContainsString(
                'class="field-bar"',
                $html,
                "{$path} does not use .field-bar for its filter row."
            );
        }
    }

    public function test_no_hint_renders_inside_a_field_bar(): void
    {
        $owner = $this->bootBusiness()->owner;

        foreach ($this->filterPages() as $path) {
            $html = $this->actingAs($owner)->get($path)->assertOk()->getContent();

            // The bar always sits inside its form, so everything up to the
            // form's end is the bar plus the row note - and the note is a
            // .filter-note, never a .form-hint.
            preg_match('#<div class="field-bar">(.*?)</form>#s', $html, $region);

            $this->assertNotEmpty($region, "{$path} has no field bar to check.");

            $this->assertStringNotContainsString(
                'form-hint',
                $region[1],
                "{$path} renders a hint inside its field bar. The hint makes its column "
                .'taller and the end-aligned button drops to the hint level instead of the '
                .'input level. Put the text below the bar as .filter-note.'
            );
        }
    }

    public function test_no_form_aligns_itself_with_an_inline_style(): void
    {
        /*
         * An end-aligned row written inline cannot carry the margin reset or
         * the no-hints rule, so it misaligns the same way trial-balance did.
         * The dashboard's 7-day chart is the one legitimate inline flex-end
         * left, and it is exempt by construction: it is not inside a form.
         */
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = File::get($file->getPathname());

            preg_match_all('#<form\b.*?</form>#s', $source, $forms);

            foreach ($forms[0] as $form) {
                if (preg_match('/align-items:\s*flex-end/', $form)) {
                    $offenders[] = $file->getRelativePathname();
                    break;
                }
            }
        }

        $this->assertEmpty(
            $offenders,
            'End-aligned rows inside forms must use .field-bar, which zeroes the field '
            .'margin and forbids hints inside the row: '.implode(', ', $offenders)
        );
    }

    public function test_the_field_bar_class_zeroes_the_field_margin(): void
    {
        $css = File::get(public_path('css/forms.css'));

        preg_match('/\.field-bar\s*\{([^}]*)\}/s', $css, $bar);

        $this->assertNotEmpty($bar, '.field-bar has no rule.');

        $this->assertMatchesRegularExpression(
            '/align-items\s*:\s*flex-end/',
            $bar[1],
            '.field-bar must end-align its row, or the button floats beside the label.'
        );

        $this->assertMatchesRegularExpression(
            '/\.field-bar\s+\.form-group\s*\{[^}]*margin-bottom\s*:\s*0/',
            $css,
            '.field-bar must zero the field margin: end-alignment aligns margin edges, '
            .'so the 16px .form-group margin hangs the button below the input.'
        );

        $this->assertMatchesRegularExpression(
            '/\.filter-note\s*\{/',
            $css,
            '.filter-note must exist: it is where row-level help text goes instead of '
            .'inside the bar.'
        );
    }
}
