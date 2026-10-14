<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The header account cluster: language, theme and the signed-in user.
 *
 * One partial serves the admin topbar and the till header, and its styles
 * must live in a stylesheet both layouts load. They lived in pos.css, which
 * the admin never loads - so the account trigger rendered as a native browser
 * button in the topbar: a grey slab beside two ghost icons.
 *
 * The trigger is transparent by design (avatar plus name, filled only on hover
 * and while its menu is open), which this locks together with the ownership:
 * a slab cannot come back through either file.
 */
class AccountTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_trigger_styles_live_in_the_shared_stylesheet(): void
    {
        $shared = File::get(public_path('css/components.css'));
        $till = File::get(public_path('css/pos.css'));

        foreach (['.pos-account', '.pos-icon-btn'] as $selector) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($selector, '/').'\s*\{/',
                $shared,
                "{$selector} must be defined in the shared components.css - the admin topbar "
                .'never loads pos.css.'
            );

            $this->assertDoesNotMatchRegularExpression(
                '/'.preg_quote($selector, '/').'\s*\{/',
                $till,
                "{$selector} is defined in pos.css too. Two definitions is a race the later "
                .'file always wins - one owner only.'
            );
        }
    }

    public function test_the_trigger_is_transparent_until_hovered_or_open(): void
    {
        $css = File::get(public_path('css/components.css'));

        preg_match('/\.pos-account\s*\{([^}]*)\}/', $css, $rule);

        $this->assertNotEmpty($rule, '.pos-account has no rule.');

        $this->assertMatchesRegularExpression(
            '/background\s*:\s*(transparent|none)/',
            $rule[1],
            '.pos-account must be transparent at rest. A filled trigger reads as a slab '
            .'beside the ghost icon buttons.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/border\s*:\s*1px/',
            $rule[1],
            '.pos-account must not carry a resting border - the slab came back.'
        );

        $this->assertMatchesRegularExpression(
            '/\.pos-account\[aria-expanded="true"\]/',
            $css,
            'The open menu must keep its trigger lit via aria-expanded, which app.js maintains.'
        );
    }

    public function test_the_trigger_matches_its_row_height(): void
    {
        $css = File::get(public_path('css/components.css'));

        // The admin topbar row runs at control-md, like its icon buttons.
        preg_match('/\.pos-account\s*\{([^}]*)\}/', $css, $rule);

        $this->assertMatchesRegularExpression(
            '/height\s*:\s*var\(--control-md\)/',
            $rule[1] ?? '',
            '.pos-account must match the topbar row height.'
        );

        // The till row runs one step denser.
        $this->assertMatchesRegularExpression(
            '/\.pos-account\.is-compact\s*\{[^}]*height\s*:\s*var\(--control-sm\)/',
            $css,
            'The compact trigger must match the till row height.'
        );
    }

    public function test_the_trigger_has_no_chevron_and_truncates_the_name(): void
    {
        $partial = File::get(resource_path('views/partials/account-controls.blade.php'));

        // Comments explain the absence; strip them so the rule does not match
        // its own documentation.
        $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $partial);

        $this->assertStringNotContainsString(
            'chevron',
            $markup,
            'The account trigger grew a chevron. Beside two icon buttons it already reads '
            .'as a header control; the glyph competes with the avatar.'
        );

        $this->assertStringContainsString(
            'pos-account-name',
            $partial,
            'The name needs its truncation class, or a long name pushes the row apart.'
        );

        $this->assertEquals(
            2,
            substr_count($partial, 'aria-expanded="false"'),
            'Both dropdown triggers must declare their initial expanded state for the open fill.'
        );
    }

    public function test_the_avatar_is_round_and_neutral(): void
    {
        $css = File::get(public_path('css/components.css'));

        preg_match('/\.avatar\s*\{([^}]*)\}/', $css, $rule);

        $this->assertNotEmpty($rule, '.avatar has no rule.');

        $this->assertMatchesRegularExpression(
            '/border-radius\s*:\s*var\(--radius-pill\)/',
            $rule[1],
            'Avatars are round marks, not squares.'
        );

        $this->assertMatchesRegularExpression(
            '/border\s*:\s*1px/',
            $rule[1],
            'The avatar needs its border: on pure black nothing else carries it.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/background\s*:\s*var\(--color-primary\)/',
            $rule[1],
            'The avatar must not wear the brand fill - beside ghost icon buttons it reads '
            .'as a third, louder button.'
        );
    }

    public function test_dropdown_triggers_maintain_their_expanded_state(): void
    {
        $js = File::get(public_path('js/app.js'));

        $this->assertStringContainsString(
            "setAttribute('aria-expanded'",
            $js,
            'Dropdown triggers must maintain aria-expanded: the open fill keys off it.'
        );
    }
}
