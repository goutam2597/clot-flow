<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Buttons inside forms.
 *
 * `x-ui.button` defaults to type="button" — deliberately, because a button
 * placed next to a form should never submit it by accident. The cost of that
 * default is that a button which *is* meant to submit has to ask, and asking
 * is easy to forget: six delete buttons across the app did not, and every one
 * of them did nothing at all, with no error anywhere.
 *
 * These assertions read the markup rather than the rendering, because the whole
 * failure mode is invisible until someone clicks.
 */
class ButtonActionTest extends TestCase
{
    /** @return array<string, string> */
    protected function views(): array
    {
        $out = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if ($file->getExtension() === 'php') {
                // Normalised: getRelativePathname() uses the OS separator, and
                // a path comparison that only works on Linux is a test that
                // silently stops checking anything on a Windows machine.
                $name = str_replace('\\', '/', $file->getRelativePathname());

                $out[$name] = File::get($file->getPathname());
            }
        }

        return $out;
    }

    public function test_every_destroy_form_uses_the_shared_delete_component(): void
    {
        $offenders = [];

        // These two ARE where the pattern legitimately lives: they define a
        // DELETE form rather than consume one.
        $components = [
            'components/ui/delete-form.blade.php',
            'components/ui/bulk-form.blade.php',
        ];

        foreach ($this->views() as $name => $view) {
            if (in_array($name, $components, true)) {
                continue;
            }

            // A hand-written DELETE form is the thing that broke. The component
            // is the only supported way to spell one.
            if (preg_match('/<form[^>]*method="POST"[\s\S]{0,400}?@method\(\'DELETE\'\)/', $view)) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These views hand-assemble a DELETE form instead of using <x-ui.delete-form>:\n  "
            .implode("\n  ", $offenders)
        );
    }

    public function test_every_button_inside_a_form_asks_to_submit_or_explicitly_does_not(): void
    {
        $offenders = [];

        foreach ($this->views() as $name => $view) {
            // Only raw markup: a Blade component reference has its attributes
            // resolved at compile time, so only a literally-written <button>
            // inside a <form> can be checked here.
            if (! preg_match_all('/<form\b[\s\S]*?<\/form>/', $view, $forms)) {
                continue;
            }

            foreach ($forms[0] as $form) {
                if (! preg_match_all('/<button\b[^>]*>/', $form, $buttons)) {
                    continue;
                }

                foreach ($buttons[0] as $button) {
                    // A button with no type is a submit button by HTML default,
                    // so it needs no annotation.
                    if (preg_match('/\btype="(submit|button|reset)"/', $button)) {
                        continue;
                    }

                    $offenders[] = $name.': '.preg_replace('/\s+/', ' ', trim($button));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These <button> elements sit inside a form with no type, so whether they submit is "
            ."a guess:\n  ".implode("\n  ", array_slice($offenders, 0, 20))
        );
    }

    public function test_no_view_writes_a_raw_checkbox(): void
    {
        $offenders = [];

        foreach ($this->views() as $name => $view) {
            // These two are where the element legitimately lives: the component
            // and the partial it shares the box with across its variants.
            if (in_array($name, [
                'components/ui/checkbox.blade.php',
                'partials/checkbox-box.blade.php',
            ], true)) {
                continue;
            }

            if (preg_match('/<input\b[^>]*\btype="checkbox"/', $view)) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These views write a raw checkbox. Use <x-ui.checkbox>, which carries the "
            ."generated id, the label binding, the disabled state and the indeterminate "
            ."mirror:\n  ".implode("\n  ", $offenders)
        );
    }

    public function test_the_checkbox_component_draws_its_own_control(): void
    {
        $css = File::get(public_path('css/components.css'));

        // appearance:none is what stops the operating system drawing its own
        // box over ours. Without it the accent colour wins on some platforms
        // and the palette is ignored on all of them.
        $this->assertMatchesRegularExpression(
            '/\.checkbox-input\[type="checkbox"\][^{]*\{[^}]*appearance:\s*none/s',
            $css,
            'The checkbox must switch off the native appearance or the OS draws it.'
        );

        // The drawn control hangs off the input, not a wrapper, so a checkbox
        // rendered anywhere gets the same paint as one from the component.
        $this->assertStringNotContainsString(
            '.form-check-input',
            $css,
            'The old wrapper-scoped rules are back; a checkbox outside .form-check gets no paint.'
        );
    }
    public function test_the_delete_component_submits_by_default(): void
    {
        $component = File::get(resource_path('views/components/ui/delete-form.blade.php'));

        // The regression that started all of this: the button inside this
        // component rendered type="button" and the form went nowhere.
        $this->assertMatchesRegularExpression(
            '/<x-ui\.button[^>]*type="submit"/s',
            $component,
            'x-ui.delete-form must render a submit button.'
        );

        // And it must carry the verb, or it is a POST to a DELETE-only route.
        $this->assertStringContainsString("@method('DELETE')", $component);
        $this->assertStringContainsString('@csrf', $component);
    }

    public function test_confirm_text_is_escaped_through_the_js_helper(): void
    {
        $component = File::get(resource_path('views/components/ui/delete-form.blade.php'));

        // A product named Bob's Widget, interpolated straight into a
        // single-quoted inline handler, is invalid JavaScript — and an inline
        // handler that fails to parse silently blocks the submit instead of
        // falling back, so the delete button just stops working.
        $this->assertMatchesRegularExpression(
            '/onsubmit="return confirm\(@js\(/',
            $component,
            'The confirmation must go through @js() so an apostrophe in a name cannot break it.'
        );

        $this->assertDoesNotMatchRegularExpression(
            "/onsubmit=\"return confirm\('\{\{/",
            $component,
            'Raw interpolation into an inline handler.'
        );
    }
}
