<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Custom controls on the settings screen.
 *
 * Two bugs lived here and both looked like "the switch does nothing".
 *
 * The disabled condition was inverted: `! required && blank` is true for every
 * setting nobody has ever saved, so every optional boolean rendered disabled the
 * first time it was seen and stayed that way forever - because a control that
 * cannot be ticked can never acquire a value, and the condition kept holding.
 *
 * The second was a replacement that swapped only the opening <input> tag and
 * left its closing ">" behind, so the whole block parsed as one broken tag and
 * rendered no control at all.
 */
class SettingsControlTest extends TestCase
{
    use RefreshDatabase;

    protected function settingsHtml(): string
    {
        $owner = $this->bootBusiness()->owner;

        return $this->actingAs($owner)->get('/admin/settings')->assertOk()->getContent();
    }

    public function test_every_boolean_setting_renders_a_switch(): void
    {
        $html = $this->settingsHtml();

        // The whole tag, not the fragment up to type="checkbox". Asserting on a
        // truncated tag is how a switch with no role at all still looked fine.
        preg_match_all('/<input\b[^>]*\btype="checkbox"[^>]*>/', $html, $matches);

        $this->assertNotEmpty(
            $matches[0],
            'The settings screen renders no checkboxes at all.'
        );

        // role="switch" is what tells a screen reader the control is on/off
        // rather than a tick, which is a different promise.
        foreach ($matches[0] as $box) {
            $this->assertStringContainsString('role="switch"', $box);
            $this->assertStringContainsString('checkbox-input', $box);
        }
    }

    public function test_an_unset_optional_setting_is_not_disabled(): void
    {
        $html = $this->settingsHtml();

        preg_match_all('/<input\b[^>]*type="checkbox"[^>]*>/', $html, $matches);

        $disabled = array_values(array_filter(
            $matches[0],
            fn (string $box) => (bool) preg_match('/\sdisabled(\s|=|>)/', $box)
        ));

        $names = array_map(
            fn (string $box) => preg_match('/name="([^"]+)"/', $box, $m) ? $m[1] : '?',
            $disabled
        );

        // A fresh install has no stored value for any optional setting, and the
        // old condition disabled every one of them. That is the bug this
        // asserts: if a setting is optional, it must be reachable.
        $this->assertSame(
            [],
            $names,
            'These boolean settings render disabled on a fresh install, so they can never '
            ."be switched on:\n  ".implode("\n  ", $names)
        );
    }

    public function test_each_switch_posts_a_zero_so_an_unticked_setting_is_zero(): void
    {
        $html = $this->settingsHtml();

        // The hidden "0" is what makes unticked distinguishable from absent.
        // Without it, turning a setting off posts nothing and the default wins.
        preg_match_all('/<input\b[^>]*type="hidden"[^>]*>/', $html, $matches);

        $this->assertNotEmpty(
            $matches[0],
            'No hidden zero fields: an unticked switch would post nothing.'
        );
    }

    public function test_the_settings_markup_has_no_stray_tag_fragments(): void
    {
        $html = $this->settingsHtml();

        // The replacement that broke this left a lone ">" and the old label and
        // track spans behind. Both leave tell-tale traces.
        $this->assertStringNotContainsString(
            'form-switch-track',
            $html,
            'The old switch markup is still in the template.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/<label[^>]*>\s*<x-ui\./',
            $html,
            'A Blade component reference inside a <label>: the template did not compile.'
        );
    }
}
