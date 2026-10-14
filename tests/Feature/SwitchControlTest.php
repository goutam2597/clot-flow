<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Business;
use Tests\TestCase;

/**
 * Switches and labelled checkboxes: always the component, never hand-rolled.
 *
 * Two pages wrapped `x-ui.checkbox` in a hand-made `label.form-switch` with a
 * separate track span. That markup cannot work: the input ends up buried
 * inside the component, so `input:checked + track` never matches and the knob
 * never moves - the value toggles invisibly and the switch reads as dead. The
 * wrapping label also nested a second label (invalid HTML) and printed the
 * text twice. Login had the milder form: a wrapped checkbox plus a duplicate
 * text span.
 *
 * The rule is one sentence - a form control that brings its own label must not
 * sit inside another label - and it is asserted on rendered HTML, because the
 * nesting only appears after Blade compiles the component.
 */
class SwitchControlTest extends TestCase
{
    use RefreshDatabase;

    protected function pagesWithControls(): array
    {
        return [
            '/branches',
            '/expenses/categories',
            '/login',
        ];
    }

    protected function pageHtml(string $path): string
    {
        if ($path === '/login') {
            // Logged out: the guest middleware sends an authenticated user to
            // the dashboard, which reads as a failure here.
            auth()->logout();

            return $this->get($path)->assertOk()->getContent();
        }

        // Booted once: booting per page trips unique constraints, and every
        // page belongs to the same business anyway.
        $this->business ??= $this->bootBusiness();
        $owner = $this->business->owner;

        if ($path === '/expenses/categories') {
            $owner->givePermissionTo('expenses.view');
        }

        return $this->actingAs($owner)->get($path)->assertOk()->getContent();
    }

    protected ?Business $business = null;

    public function test_no_label_is_nested_inside_another_label(): void
    {
        foreach ($this->pagesWithControls() as $path) {
            $html = $this->pageHtml($path);

            preg_match_all('#</?label\b[^>]*>#', $html, $tags);

            $this->assertNotEmpty($tags[0], "{$path} renders no labels at all.");

            $depth = 0;

            foreach ($tags[0] as $tag) {
                $depth += str_starts_with($tag, '</') ? -1 : 1;

                $this->assertLessThanOrEqual(
                    1,
                    $depth,
                    "{$path} nests a <label> inside another <label>. A component that brings "
                    .'its own label must not sit inside a hand-written one - the association '
                    .'breaks and the text prints twice.'
                );

                $this->assertGreaterThanOrEqual(0, $depth, "{$path} has unbalanced label tags.");
            }
        }
    }

    public function test_no_hand_rolled_switch_track_remains_in_any_view(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains(File::get($file->getPathname()), 'form-switch-track')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertEmpty(
            $offenders,
            'A hand-rolled switch track cannot work with the checkbox component - the input '
            .'can never be its sibling, so :checked never reaches it. Use '
            .'x-ui.checkbox variant="switch": '.implode(', ', $offenders)
        );
    }

    public function test_the_legacy_switch_selectors_are_gone_from_the_stylesheets(): void
    {
        $css = '';

        foreach (File::files(public_path('css')) as $file) {
            $css .= File::get($file->getPathname());
        }

        // Comments mention the old selectors to explain why they are gone;
        // strip them so the rule does not match its own documentation.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertDoesNotMatchRegularExpression(
            '/\.form-switch\b/',
            $css,
            'Legacy .form-switch selectors remain but no view may use them.'
        );
    }

    public function test_the_branch_switch_is_one_component_with_a_hidden_zero(): void
    {
        $html = $this->pageHtml('/branches');

        // The component, exactly the component: the hint rides on it rather
        // than sitting beside a dead track as a second label. (The text
        // appears once per form - add plus each row's edit dialog - so the
        // single-label proof is the absence of the legacy span, not a count.)
        $this->assertStringContainsString('checkbox-switch', $html);
        $this->assertStringNotContainsString('form-switch-track', $html);
        $this->assertStringNotContainsString(
            'form-check-label',
            $html,
            'A duplicate label span sits beside the component.'
        );
        $this->assertStringContainsString(
            __('Active — branch is selectable for new records'),
            $html,
            'The hint was lost with the old markup.'
        );

        // Unticked posts 0 via the hidden field; without it, off is
        // indistinguishable from absent.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="hidden"[^>]*name="is_active"[^>]*value="0"/',
            $html,
            'No hidden zero for is_active: an unticked switch would post nothing.'
        );
    }

    public function test_the_login_remember_me_renders_a_single_label(): void
    {
        $html = $this->pageHtml('/login');

        $this->assertEquals(
            1,
            substr_count($html, __('Keep me signed in')),
            'The remember-me text renders more than once: a wrapped component plus a duplicate span.'
        );
    }

    public function test_a_switch_with_a_hint_aligns_to_the_label_line(): void
    {
        /*
         * Centered, the 22px track sits level with the midpoint between label
         * and hint - about 9px below the label it belongs to. Top-aligning
         * the track puts it back on the label's line; switches without a hint
         * keep centering, where a 22px track against one line is within a pixel.
         */
        $css = File::get(public_path('css/components.css'));

        $this->assertMatchesRegularExpression(
            '/\.checkbox-switch:has\(\.checkbox-hint\)\s*\{[^}]*align-items\s*:\s*flex-start/',
            $css,
            'A hinted switch must top-align its track with the label line.'
        );

        // ...and the input goes with it. The input centers on the whole label
        // by default, so once the track top-aligns the two separate and the
        // teal pill hangs below the track. Pinning the input to the label top
        // keeps it exactly over the track.
        $this->assertMatchesRegularExpression(
            '/\.checkbox-switch:has\(\.checkbox-hint\)\s+\.checkbox-input\s*\{[^}]*inset-block-start\s*:\s*0/',
            $css,
            'A hinted switch must pin its absolute input to the label top, over the track.'
        );
    }

    public function test_a_checkbox_in_a_split_row_brings_no_stacked_margin(): void
    {
        // Login's remember-me sits beside the reset link in a centered row:
        // the stacked-form bottom margin would lift the link off the box.
        $css = File::get(public_path('css/forms.css'));

        $this->assertMatchesRegularExpression(
            '/\.flex-between\s*>\s*\.checkbox-field\s*\{[^}]*margin-bottom\s*:\s*0/',
            $css,
            'A checkbox standing in a split row must drop its stacked-form margin.'
        );
    }
}
