<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The till is a chrome-free surface.
 *
 * It used to render inside the admin shell, which meant a 16rem sidebar and a
 * topbar stood between the cashier and the product grid - roughly a third of a
 * 15" counter display spent on navigation nobody needs mid-sale, and a route
 * back to the admin one stray keystroke away.
 *
 * Two things have to stay true together, and this file exists because it is
 * easy to get only one of them:
 *
 *   1. no sidebar and no topbar, and
 *   2. a way to sign out anyway.
 *
 * Stripping the chrome without moving the account controls into the till header
 * would leave a cashier unable to end a shift without abandoning the basket on
 * screen. Both halves are asserted here rather than left to review.
 */
class TillLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function till(): string
    {
        $owner = $this->bootBusiness()->owner;

        return $this->actingAs($owner)->get('/pos')->assertOk()->getContent();
    }

    public function test_the_till_renders_without_a_sidebar_or_a_topbar(): void
    {
        $html = $this->till();

        $this->assertStringNotContainsString('class="sidebar"', $html, 'The till has a sidebar.');
        $this->assertStringNotContainsString('class="topbar"', $html, 'The till has a topbar.');
        $this->assertStringNotContainsString('data-sidebar-toggle', $html);
    }

    public function test_the_till_uses_its_own_layout(): void
    {
        $html = $this->till();

        $this->assertStringContainsString('pos-document', $html);
        $this->assertStringContainsString('<body class="pos-viewport"', $html);
    }

    public function test_the_document_body_carries_no_flex_layout(): void
    {
        /*
         * The body class was once pos-body, which is also the two-column row's
         * class. The row's `display: flex` then turned the whole document into
         * a flex row: the main stopped stretching and the shell sized to its
         * content (1542px inside a 1700px window), leaving a band of canvas
         * down the right side that no amount of window-maximising could fill.
         *
         * Rather than asserting one particular class name, this guards the
         * general case: no class on the document body may establish a flex
         * container. A flex document lays its children out as siblings in a
         * row instead of stacking them full-width, which is never what a
         * document wants.
         */
        $html = $this->till();

        preg_match('/<body[^>]*class="([^"]*)"/', $html, $match);

        $this->assertNotEmpty($match, 'The till has no body class to check.');

        $bodyClasses = preg_split('/\s+/', $match[1]);

        preg_match_all(
            '/\.([a-z0-9-]+)\s*\{[^}]*display\s*:\s*flex[^}]*\}/i',
            $this->tokens(),
            $flex
        );

        $collision = array_intersect($bodyClasses, $flex[1] ?? []);

        $this->assertEmpty(
            $collision,
            'The document body shares a class with a flex container: '
            .implode(', ', $collision).'. A flex document does not stretch its '
            .'children full-width, so the till stops filling the window.'
        );
    }

    public function test_the_switch_label_establishes_a_containing_block(): void
    {
        /*
         * The switch input is absolutely positioned over its own track, and
         * the base `.checkbox` is deliberately unpositioned. Without
         * `position: relative` on the switch label the input escapes to the
         * viewport and renders as a stray grey pill at the left edge of the
         * screen, at half the window height - while the real track stays
         * behind in the header, so the switch looks fine where it should be
         * and wrong somewhere nobody looks.
         */
        $css = \Illuminate\Support\Facades\File::get(public_path('css/components.css'));

        preg_match('/\.checkbox-switch\s*\{([^}]*)\}/', $css, $rule);

        $this->assertNotEmpty($rule, '.checkbox-switch has no rule of its own.');

        $this->assertMatchesRegularExpression(
            '/position\s*:\s*relative/',
            $rule[1],
            '.checkbox-switch must establish the containing block for its absolute input.'
        );
    }

    public function test_the_till_can_still_sign_out(): void
    {
        $html = $this->till();

        // Without a sidebar or a topbar, this is the only route off the screen.
        $this->assertStringContainsString(route('logout'), $html, 'No way to sign out of a shift.');

        $this->assertMatchesRegularExpression(
            '/<form[^>]*action="[^"]*logout[\s\S]{0,200}?<button[^>]*type="submit"/',
            $html,
            'Sign out must be a submit button inside its form, not a link.'
        );
    }

    public function test_the_till_keeps_the_theme_toggle_and_language_switch(): void
    {
        $html = $this->till();

        $this->assertStringContainsString('data-theme-toggle', $html);
        $this->assertStringContainsString('pos-account', $html);
    }

    public function test_every_control_in_the_till_header_shares_one_height(): void
    {
        $owner = $this->bootBusiness()->owner;

        // A second active language, so the language dropdown renders the way it
        // does in production. With a single language there is no dropdown and
        // the row under test loses a control - including the dropdown-in-row
        // case this test exists to cover.
        \App\Models\Language::create([
            'name' => 'Bengali',
            'native_name' => 'Bangla',
            'code' => 'bn',
            'locale' => 'bn',
            'direction' => 'ltr',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 10,
        ]);

        $html = $this->actingAs($owner)->get('/pos')->assertOk()->getContent();

        // The header row now mixes buttons, icon buttons, a switch, a readout
        // and a dropdown, all from different rules. The design system's rule is
        // that they match, and this is the one row where a mismatch is visible
        // at a glance from across a counter.
        preg_match('#<div class="pos-header-actions">(.*?)</div>\s*</header>#s', $html, $row);

        $this->assertNotEmpty($row, 'The till header row is missing.');

        preg_match_all('/<button\b[^>]*\bclass="([^"]*)"/s', $row[1], $buttons);

        $css = $this->tokens();
        $map = $this->variableMap($css);

        // Dropdown menu items are descendants of the header, so the row capture
        // picks them up. They are menu rows, not header controls, and they carry
        // no size class by design.
        $controls = array_values(array_filter(
            $buttons[1],
            fn (string $class) => ! str_contains($class, 'dropdown-item')
        ));

        $expected = (float) $this->remToPx($this->token($map, '--control-sm'));

        foreach ($controls as $class) {
            $this->assertMatchesRegularExpression(
                '/\bbtn-sm\b|pos-icon-btn|pos-account|pos-toggle|pos-session-total/',
                $class,
                "A control in the till header has no size class, so it falls back to .btn's "
                ."default {$expected}px instead of the row's: \"{$class}\""
            );
        }

        $this->assertGreaterThanOrEqual(5, count($controls), 'The header row looks empty.');
    }

    public function test_the_till_does_not_scroll_the_page(): void
    {
        // Asserted on the stylesheet rather than in a browser: the invariant is
        // that the shell owns the viewport, and that is expressed in one rule.
        $css = $this->tokens();

        preg_match('/\.pos-shell\s*\{([^}]*)\}/s', $css, $rule);

        $this->assertNotEmpty($rule, '.pos-shell must exist.');

        $this->assertStringNotContainsString(
            'calc(100vh',
            $rule[1],
            '.pos-shell still subtracts the topbar height. The till has no topbar now, so '
            .'that arithmetic leaves a gap at the bottom.'
        );

        $this->assertMatchesRegularExpression(
            '/height:\s*100dvh/',
            $rule[1],
            '.pos-shell must claim the viewport outright.'
        );
    }

    /** @return array<string, string> */
    protected function tokens(): string
    {
        $css = '';

        foreach (\Illuminate\Support\Facades\File::files(public_path('css')) as $file) {
            $css .= \Illuminate\Support\Facades\File::get($file->getPathname());
        }

        return $css;
    }

    protected function variableMap(string $css): array
    {
        $map = [];

        if (preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $css, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = trim($match[1]);

                if (! array_key_exists($name, $map)) {
                    $map[$name] = trim($match[2]);
                }
            }
        }

        return $map;
    }

    protected function token(array $map, string $name): string
    {
        $this->assertArrayHasKey($name, $map, "Design token {$name} is not defined.");

        $value = $map[$name];

        while (preg_match('/var\(\s*(--[a-z0-9-]+)\s*\)/i', $value, $m)) {
            $value = $map[$m[1]];
        }

        return $value;
    }

    protected function remToPx(string $value): float
    {
        if (preg_match('/^([\d.]+)px$/', $value, $m)) {
            return (float) $m[1];
        }

        if (preg_match('/^([\d.]+)rem$/', $value, $m)) {
            return (float) $m[1] * 16;
        }

        $this->fail("{$value} is not a length this test can compare.");
    }
}
