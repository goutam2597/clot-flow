<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The UI consistency rules the product is specified against.
 *
 * These are cheap to break by hand-editing a template and expensive to notice,
 * so they are asserted rather than trusted: the 12px radius ceiling, the strict
 * control heights, and the ban on the "AI dashboard" colour palette.
 */
class DesignSystemTest extends TestCase
{
    use RefreshDatabase;

    /** The radius ceiling from the specification. */
    protected const MAX_RADIUS_PX = 12;

    /**
     * Control heights the specification fixes.
     */
    protected const CONTROL_HEIGHTS = [
        'sm' => '36px',
        'md' => '40px',
        'lg' => '44px',
    ];

    protected function tokens(): string
    {
        $css = '';

        foreach (File::files(public_path('css')) as $file) {
            $css .= File::get($file->getPathname());
        }

        return $css;
    }

    /** @return array<string, string> */
    protected function variableMap(string $css): array
    {
        $map = [];

        if (preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $css, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = trim($match[1]);
                $value = trim($match[2]);

                // Keep the first definition: :root is the light theme source of
                // truth, [data-theme="dark"] overrides it.
                if (! array_key_exists($name, $map)) {
                    $map[$name] = $value;
                }
            }
        }

        return $map;
    }

    protected function token(string|array $css, string $name): string
    {
        $map = is_array($css) ? $css : $this->variableMap($css);

        $this->assertArrayHasKey($name, $map, "Design token {$name} is not defined.");

        return $map[$name];
    }

    public function test_the_control_height_tokens_are_exactly_as_specified(): void
    {
        $css = $this->tokens();

        $this->assertSame('36px', $this->token($css, '--control-sm'));
        $this->assertSame('40px', $this->token($css, '--control-md'));
        $this->assertSame('44px', $this->token($css, '--control-lg'));
    }

    public function test_buttons_and_inputs_use_the_same_control_height(): void
    {
        $css = $this->tokens();

        // This is the rule that a button sitting beside a text field must line
        // up: both must resolve to the same --control-md.
        $this->assertMatchesRegularExpression(
            '/\.btn\s*\{[^}]*height:\s*var\(--control-md\)/s',
            $css,
            '.btn must use the default control height.'
        );
    }

    public function test_form_controls_use_the_default_control_height(): void
    {
        $css = $this->tokens();

        $this->assertMatchesRegularExpression(
            '/\.form-control\s*\{[^}]*height:\s*var\(--control-md\)/s',
            $css,
            '.form-control must use the default control height so it aligns with adjacent buttons.'
        );
    }

    public function test_no_stylesheet_nests_a_rule_inside_another(): void
    {
        /*
         * Brace balance alone is not enough, and that is exactly how a missing
         * `}` survived: `.alert { … ` was never closed, so every rule after it
         * was parsed as a *nested* rule. The braces balanced, the file parsed
         * without an error, and the browser silently discarded the whole block
         * — every table, pagination control and filter bar in the product
         * rendered unstyled while every test stayed green.
         *
         * A style rule may only appear at depth 0 or depth 1 (inside @media).
         * Anything deeper is a rule swallowed by an unclosed block.
         */
        $offenders = [];

        foreach (File::files(public_path('css')) as $file) {
            $source = preg_replace('#/\*.*?\*/#s', '', File::get($file->getPathname()));

            $depth = 0;
            $line = 1;
            $seenDeclaration = false;
            $atDepthZero = false;

            foreach (str_split($source) as $char) {
                if ($char === "\n") {
                    $line++;
                    $seenDeclaration = false;
                    continue;
                }

                if ($char === ';') {
                    $seenDeclaration = true;
                    continue;
                }

                if ($char === '{') {
                    // A declaration directly before this brace means the block
                    // that just ended was never given its own name.
                    if ($depth === 1 && $seenDeclaration) {
                        $offenders[] = "{$file->getFilename()}:{$line} a rule is nested inside an unclosed block";
                    }

                    if ($depth === 0 && $seenDeclaration) {
                        $offenders[] = "{$file->getFilename()}:{$line} declaration outside any block";
                    }

                    $depth++;
                    $seenDeclaration = false;
                    continue;
                }

                if ($char === '}') {
                    $depth--;
                    $seenDeclaration = false;
                    continue;
                }
            }
        }

        $this->assertSame([], $offenders, "Nested CSS rules:\n" . implode("\n", $offenders));
    }

    public function test_every_stylesheet_parses_without_orphaned_declarations(): void
    {
        /*
         * A CSS parser stops at the first structural error and silently discards
         * everything it was in the middle of reading. A stray `}` closing a
         * rule early therefore does not raise an error anywhere — it just
         * leaves the rest of the file as garbage the browser throws away, and
         * the page renders with half its tokens missing and no console message.
         *
         * The `test_every_css_variable_reference_resolves` check cannot catch
         * this: it scans raw text, so a declaration that the browser has
         * already discarded still looks defined. This walks the braces instead.
         */
        $offenders = [];

        foreach (File::files(public_path('css')) as $file) {
            // Strip comments first — a brace inside one is not structural.
            $source = preg_replace('#/\*.*?\*/#s', '', File::get($file->getPathname()));

            $depth = 0;
            $line = 1;
            // Text accumulated at depth 0 since the last block ended.
            $stray = '';

            foreach (str_split($source) as $char) {
                if ($char === "\n") {
                    $line++;
                    $stray .= "\n";
                    continue;
                }

                if ($char === '{') {
                    $depth++;
                    // Selectors and declarations alike are only "at depth 0"
                    // up to the brace that starts their block.
                    $stray = '';
                    continue;
                }

                if ($char === '}') {
                    $depth--;

                    if ($depth < 0) {
                        $offenders[] = "{$file->getFilename()}:{$line} closes a block that was never opened";
                        $depth = 0;
                    }

                    $stray = '';
                    continue;
                }

                if ($depth > 0) {
                    continue;
                }

                if ($char === ';') {
                    $offenders[] = sprintf(
                        '%s:%d declaration outside any block: %s',
                        $file->getFilename(),
                        $line,
                        trim(preg_replace('/\s+/', ' ', $stray)) ?: '(empty)'
                    );

                    $stray = '';
                    continue;
                }

                $stray .= $char;
            }

            if ($depth !== 0) {
                $offenders[] = "{$file->getFilename()} has {$depth} unclosed block(s)";
            }
        }

        $this->assertSame([], $offenders, "Malformed CSS:\n" . implode("\n", $offenders));
    }

    public function test_the_token_block_actually_applies_in_a_browser(): void
    {
        /*
         * The token file is the one thing every other rule depends on. Assert
         * that the structural tokens are all declared inside a single, closed
         * `:root` block — so a regression that truncates the cascade fails here
         * rather than as an unexplained unstyled screen.
         */
        $css = File::get(public_path('css/base.css'));

        // Isolate the :root block and make sure it is not silently truncated.
        preg_match('/\n:root\s*\{(.*?)\n\}/s', $css, $match);
        $this->assertNotEmpty($match, 'base.css must open a :root token block.');

        $block = $match[1];

        foreach ([
            '--brand-700',
            '--color-primary',
            '--bg-surface',
            '--text-primary',
            '--border-color',
            '--font-sans',
            '--fs-base',
            '--space-4',
            '--control-sm',
            '--control-md',
            '--control-lg',
            '--radius-md',
            '--focus-ring',
            '--content-max',
        ] as $token) {
            $this->assertStringContainsString(
                $token . ':',
                $block,
                "{$token} must be declared inside the :root token block, not after it."
            );
        }

        // The chart palette has to exist or every report page renders blank.
        for ($i = 1; $i <= 8; $i++) {
            $this->assertStringContainsString("--chart-{$i}:", $block);
        }
    }

    public function test_every_css_variable_reference_resolves(): void
    {
        // An undefined var() makes the browser drop the whole declaration it
        // appears in, silently stripping padding or gaps with no error. The
        // auth panel lost its padding exactly this way.
        $defined = array_keys($this->variableMap($this->tokens()));
        $undeclared = [];

        foreach (File::files(public_path('css')) as $file) {
            $css = File::get($file->getPathname());

            if (! preg_match_all('/var\(\s*(--[a-zA-Z0-9-]+)/', $css, $matches)) {
                continue;
            }

            foreach ($matches[1] as $name) {
                if (! in_array($name, $defined, true)) {
                    $undeclared[$name][] = $file->getFilename();
                }
            }
        }

        $report = [];

        foreach ($undeclared as $name => $files) {
            $report[] = "{$name} used in " . implode(', ', array_unique($files)) . ' but never defined';
        }

        $this->assertSame([], $report, "Undefined CSS variables:\n" . implode("\n", $report));
    }

    public function test_stat_cards_in_a_row_are_forced_to_equal_height(): void
    {
        $css = $this->tokens();

        // A stat card carries an optional hint line. Without an explicit height
        // the card without a hint renders shorter and the row goes ragged,
        // which is exactly what a mixed dashboard shows.
        $this->assertMatchesRegularExpression(
            '/\.stat-card\s*\{[^}]*height:\s*100%/s',
            $css,
            '.stat-card must fill its track so every card in a row matches.'
        );

        // Pinning the hint to the bottom keeps the values on a shared baseline.
        $this->assertMatchesRegularExpression(
            '/\.stat-card-meta\s*\{[^}]*margin-top:\s*auto/s',
            $css,
            '.stat-card-meta must sit at the bottom of the card.'
        );
    }

    public function test_a_shared_stat_grid_helper_exists(): void
    {
        $css = $this->tokens();

        // Pages should use the shared grid rather than repeating inline flex
        // wrappers, which is what let the height bug through in the first place.
        $this->assertMatchesRegularExpression(
            '/\.stat-grid\s*\{[^}]*display:\s*grid/s',
            $css
        );
    }

    public function test_the_dashboard_uses_the_shared_stat_grid(): void
    {
        $source = File::get(resource_path('views/dashboard/index.blade.php'));

        $this->assertStringContainsString('class="stat-grid"', $source);
        $this->assertStringNotContainsString(
            'flex: 1 1 190px',
            $source,
            'The dashboard still hand-rolls flex wrappers instead of using .stat-grid.'
        );
    }

    public function test_content_is_full_width_by_default(): void
    {
        // A max-width letterboxes data tables on the warehouse monitors this
        // product is actually used on.
        $this->assertSame('none', $this->token($this->tokens(), '--content-max'));
    }

    public function test_no_border_radius_token_exceeds_the_twelve_pixel_ceiling(): void
    {
        $css = $this->tokens();

        foreach ($this->variableMap($css) as $name => $value) {
            if (! str_contains($name, 'radius') || $name === '--radius-pill') {
                continue;
            }

            // A bare pixel value; rem/percent values are not checked here.
            if (preg_match('/^([\d.]+)px$/', trim($value), $m)) {
                $this->assertLessThanOrEqual(
                    self::MAX_RADIUS_PX,
                    (float) $m[1],
                    "{$name} is {$value}, above the 12px ceiling."
                );
            }
        }
    }

    /**
     * The boxy palette.
     *
     * The brand is a pine at hue ~188 and the neutrals are warm greys at hue
     * ~85. Both are asserted because they are the two decisions that separate
     * this product from a generated dashboard: a blue-grey primary reads cold
     * and clinical, and a cold neutral beside a warm brand reads dirty.
     */
    public function test_the_brand_is_a_deep_pine_not_an_electric_blue(): void
    {
        $map = $this->variableMap($this->tokens());
        $brand = $this->resolve($this->token($this->tokens(), '--brand-700'), $map);

        $this->assertStringStartsWith('oklch(', $brand);
        $this->assertEqualsWithDelta(188, $this->oklchHue($brand), 1, 'The brand must sit on the pine hue.');

        // Dark enough to carry white label text at WCAG AA.
        $this->assertLessThan(0.45, $this->oklchLightness($brand));
    }

    public function test_the_neutrals_are_warm_tinted(): void
    {
        $canvas = $this->resolve($this->token($this->tokens(), '--bg-app'), $this->variableMap($this->tokens()));

        $this->assertStringStartsWith('oklch(', $canvas);
        $this->assertEqualsWithDelta(
            85,
            $this->oklchHue($canvas),
            12,
            'The canvas must be a warm grey. A blue-tinted neutral beside a warm brand reads dirty.'
        );
    }

    /**
     * Resolve a token to its literal value, following `var(--other)` aliases.
     *
     * Tokens are allowed to alias each other — that is what keeps a palette from
     * drifting when the brand shifts — but a test that reads them has to follow
     * the chain or it silently measures the literal string "var(--brand-700)".
     */
    protected function resolve(string $value, array $map, int $depth = 0): string
    {
        $this->assertLessThan(8, $depth, 'Token alias chain is circular.');

        if (preg_match('/^var\(\s*(--[a-z0-9-]+)\s*\)$/i', trim($value), $m)) {
            $this->assertArrayHasKey(
                $m[1],
                $map,
                "{$m[1]} is referenced but never defined."
            );

            return $this->resolve($map[$m[1]], $map, $depth + 1);
        }

        return trim($value);
    }

    /**
     * The inner HTML of the first element carrying a class, by balanced scan.
     *
     * A non-greedy `(.*?)</div>` is the obvious way to do this and it is wrong
     * the moment anything nests: it stops at the first closing tag of a child
     * rather than at the end of the element, so the test asserts against a
     * fragment of the row and reports a mismatch that isn't there.
     */
    protected function innerHtmlOfClass(string $html, string $class): string
    {
        $tag = str_contains($class, '-') ? 'div' : 'span';

        if (! preg_match('/<'.$tag.'\b[^>]*\bclass="[^"]*\b'.preg_quote($class, '/').'\b[^"]*"[^>]*>/', $html, $open, PREG_OFFSET_CAPTURE)) {
            $this->fail("No <{$tag}> with class \"{$class}\" was rendered.");
        }

        $start = $open[0][1] + strlen($open[0][0]);
        $depth = 1;
        $cursor = $start;
        $pattern = '#</?'.$tag.'\b[^>]*>#i';

        while ($depth > 0 && preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $cursor)) {
            $depth += str_starts_with($m[0][0], '</') ? -1 : 1;
            $cursor = $m[0][1] + strlen($m[0][0]);
        }

        $this->assertSame(0, $depth, "The <{$tag} class=\"{$class}\"> element is never closed.");

        return substr($html, $start, $cursor - $start - strlen($tag) - 3);
    }

    /**
     * Class attributes of the buttons inside a fragment, and nothing else.
     *
     * Matching `class="[^"]*\bbtn\b[^"]*"` also matches `btn-label`, because a
     * hyphen is a non-word character and `btn` inside it is still a word. Those
     * false hits then look like size-less buttons and manufacture a height
     * mismatch out of nothing, so only the standalone `btn` token counts.
     *
     * @return array<int, string>
     */
    protected function buttonClasses(string $fragment): array
    {
        preg_match_all('/<button\b[^>]*\bclass="([^"]*)"/s', $fragment, $matches);

        return array_values(array_filter(
            $matches[1],
            fn (string $class) => in_array('btn', preg_split('/\s+/', trim($class)), true)
        ));
    }

    /** Elements the browser lays out inline, where overflow rules do not apply. */
    protected const INLINE_ELEMENTS = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'dfn', 'em',
        'i', 'kbd', 'label', 'mark', 'q', 's', 'samp', 'small', 'span',
        'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
    ];

    /**
     * Class => whether its rules force a block-level display.
     *
     * Built from the stylesheets rather than hardcoded, so adding a truncating
     * class to the CSS is enough to have it audited.
     *
     * @return array<string, bool>
     */
    protected function blockDisplayClasses(string $css): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        $classes = [];

        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER) === false) {
            return $classes;
        }

        foreach ($rules as [, $selectorList, $body]) {
            if (str_contains($body, 'display:') === false) {
                continue;
            }

            preg_match('/display\s*:\s*([a-z-]+)/', $body, $display);
            $value = $display[1] ?? '';

            $isBlock = in_array($value, ['block', 'flex', 'grid', 'table', 'table-cell', 'list-item', 'flow-root'], true);

            foreach (explode(',', $selectorList) as $selector) {
                if (preg_match('/\.([a-z0-9_-]+)/i', $selector, $class)) {
                    $name = $class[1];
                    // Any rule that forces a block display counts; a later
                    // rule cannot un-force it without also being a block.
                    $classes[$name] = ($classes[$name] ?? false) || $isBlock;
                }
            }
        }

        return $classes;
    }

    /**
     * The rendered height of a selector, in px.
     *
     * Resolves `height` then `min-height`, then resolves any var() the
     * declaration uses. Elements that get their height from a helper class
     * rather than an inline modifier — a toggle, a readout chip, a select —
     * cannot be compared by reading size classes off the markup, so the
     * stylesheet is the only place that comparison actually lives.
     */
    public function test_a_select_is_exactly_as_tall_as_the_inputs_beside_it(): void
    {
        $css = $this->tokens();
        $map = $this->variableMap($css);

        // The custom select wraps its trigger in a bordered shell. Giving the
        // trigger the control height and the shell a border adds two pixels,
        // so the field ends up 42px beside a 40px input: same class of
        // mismatch as two buttons of different sizes, and just as visible.
        // `md` is the default and carries no modifier class, so its selectors
        // are the unsuffixed ones.
        $sizes = [
            'sm' => ['--control-sm', '.select-sm .select-shell', '.select-sm:not(.is-enhanced) .select-native'],
            'md' => ['--control-md', '.select-shell', '.select:not(.is-enhanced) .select-native'],
            'lg' => ['--control-lg', '.select-lg .select-shell', '.select-lg:not(.is-enhanced) .select-native'],
        ];

        foreach ($sizes as $size => [$token, $shellSelector, $nativeSelector]) {
            $expected = (float) $this->remToPx($this->token($map, $token));

            $shell = $this->heightOfSelector($css, $shellSelector, $map);
            $native = $this->heightOfSelector($css, $nativeSelector, $map);

            foreach (['the enhanced control' => $shell, 'the no-JS fallback' => $native] as $label => $actual) {
                $this->assertSame(
                    $expected,
                    $actual,
                    "The {$size} select is ".var_export($actual, true)."px for {$label}; " .
                    "the inputs beside it are {$expected}px. Adjacent fields must match."
                );
            }
        }

        // And the trigger must not re-introduce it by claiming a height of its
        // own: it fills the shell.
        $trigger = $this->heightOfSelector($css, '.select-trigger', $map);

        $this->assertNull(
            $trigger,
            '.select-trigger declares its own height, which is added to the shell border. Let the shell own it.'
        );
    }

    /**
     * The rendered height of a selector, in px.
     *
     * Resolves `height` then `min-height`, then resolves any var() the
     * declaration uses. Elements that get their height from a helper class
     * rather than an inline modifier — a toggle, a readout chip, a select —
     * cannot be compared by reading size classes off the markup, so the
     * stylesheet is the only place that comparison actually lives.
     */
    protected function heightOfSelector(string $css, string $selector, array $map): ?float
    {
        // Parse real rule blocks rather than guessing where a selector starts:
        // a rule can follow whitespace, a comma-grouped selector list or an
        // @media block, and an anchored `\.pos-toggle {` pattern silently finds
        // nothing in two of those three cases.
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        $target = trim($selector);
        $body = null;

        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER) !== false) {
            foreach ($rules as $rule) {
                $selectors = array_map('trim', explode(',', trim($rule[1])));

                if (! in_array($target, $selectors, true)) {
                    continue;
                }

                // Later declarations of the same property win, same as the
                // cascade, so keep overwriting rather than taking the first.
                // This has to hold within a block too: `height: a; height: b;`
                // renders as b, and reading a would hide the very mismatch
                // this is looking for.
                preg_match_all('/(?:^|;)\s*(?:min-)?height\s*:\s*([^;]+)/', $rule[2], $declarations);

                if ($declarations[1] !== []) {
                    $body = trim(end($declarations[1]));
                }
            }
        }

        if ($body === null) {
            return null;
        }

        $resolved = trim($this->resolve($body, $map));

        // `auto` and the CSS-wide keywords mean "this rule does not pin a
        // height". Reporting those as absent lets a caller assert that a rule
        // does NOT claim one — which is exactly the case that lets a shell and
        // its child drift apart by the width of a border between them.
        if (in_array($resolved, ['auto', 'inherit', 'initial', 'unset', 'revert'], true)) {
            return null;
        }

        if (preg_match('/^([\d.]+)(rem|px|em)$/', $resolved) !== 1) {
            // Anything else — a calc(), a percentage, a var() that did not
            // resolve — means this rule can no longer be compared. Failing
            // loudly beats reporting a mismatch the author has to decode.
            $this->fail("\"{$selector}\" declares height: {$resolved}, which these tests cannot compare.");
        }

        return $this->remToPx($resolved);
    }

    /** A rem value in px, so type steps can be compared numerically. */
    protected function remToPx(string $value): float
    {
        $value = trim($value);

        if (preg_match('/^([\d.]+)rem$/', $value, $m)) {
            return (float) $m[1] * 16;
        }

        if (preg_match('/^([\d.]+)px$/', $value, $m)) {
            return (float) $m[1];
        }

        if (preg_match('/^([\d.]+)em$/', $value, $m)) {
            return (float) $m[1] * 16;
        }

        $this->fail("{$value} is not a length this test can compare.");
    }

    /** Pull the hue out of an oklch() token. */
    protected function oklchHue(string $value): float
    {
        preg_match('/oklch\(\s*([\d.]+)%\s+([\d.]+)\s+([\d.]+)/', $value, $m);

        $this->assertNotEmpty($m, "{$value} is not a parseable oklch() colour.");

        return (float) $m[3];
    }

    /** Pull the 0-1 perceptual lightness out of an oklch() token. */
    protected function oklchLightness(string $value): float
    {
        preg_match('/oklch\(\s*([\d.]+)%/', $value, $m);

        $this->assertNotEmpty($m, "{$value} is not a parseable oklch() colour.");

        return (float) $m[1] / 100;
    }

    public function test_no_inline_style_declares_a_radius_above_the_ceiling(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = File::get($file->getPathname());

            if (preg_match_all('/border-radius:\s*([\d.]+)px/i', $source, $matches)) {
                foreach ($matches[1] as $value) {
                    if ((float) $value > self::MAX_RADIUS_PX) {
                        $relative = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $file->getPathname());
                        $offenders[] = "{$relative} uses border-radius: {$value}px";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Radii above the ceiling:\n" . implode("\n", $offenders));
    }

    public function test_no_inline_style_declares_a_component_height(): void
    {
        // Component heights must come from the tokens, never be hand-typed on a
        // page — otherwise adjacent controls drift apart.
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = File::get($file->getPathname());

            if (preg_match_all('/(?<![a-z-])height:\s*(\d+)px/i', $source, $matches)) {
                $relative = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $file->getPathname());

                foreach ($matches[1] as $value) {
                    $allowed = ['20', '32', '34', '38', '64', '88', '96', '120', '150', '200', '260'];

                    if (! in_array($value, $allowed, true)) {
                        $offenders[] = "{$relative} hard-codes height: {$value}px";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Hand-typed component heights:\n" . implode("\n", $offenders));
    }

    public function test_the_palette_is_not_the_generic_blue_saas_default(): void
    {
        // The brand is now expressed in oklch, so there is no hex left to
        // string-match. What the old assertion was really guarding against was
        // an *electric blue* hue — 250-265 in oklch is the indigo every
        // generated dashboard reaches for. The pine sits at 188.
        $hue = $this->oklchHue($this->token($this->tokens(), '--brand-700'));

        $this->assertGreaterThan(60, $hue);
        $this->assertLessThan(
            215,
            $hue,
            'The brand hue has drifted into the generic blue/indigo range.'
        );
    }

    public function test_the_primary_colour_is_dark_enough_for_white_text(): void
    {
        // In oklch, L is perceptual lightness on a 0-1 scale and tracks
        // luminance closely enough for a contrast sanity check without pulling
        // in a full colour-management dependency. --color-primary aliases the
        // brand, so the alias has to be resolved before it can be measured.
        $map = $this->variableMap($this->tokens());
        $primary = $this->resolve($this->token($this->tokens(), '--color-primary'), $map);

        $this->assertLessThan(
            0.45,
            $this->oklchLightness($primary),
            'The primary must be dark enough to carry white label text at WCAG AA.'
        );
    }

    public function test_both_themes_define_the_same_tokens(): void
    {
        $css = $this->tokens();

        preg_match('/\[data-theme="dark"\]\s*\{(.*?)\n\}/s', $css, $dark);
        $this->assertNotEmpty($dark, 'The dark theme block is missing.');

        preg_match_all('/(--[a-z0-9-]+)\s*:/i', $dark[1], $darkVars);

        // Colours that must not be re-declared per theme.
        $structural = ['--control-sm', '--control-md', '--control-lg', '--radius-sm', '--radius-md'];

        foreach ($structural as $name) {
            $this->assertNotContains(
                $name,
                $darkVars[1],
                "{$name} must be shared by both themes so light and dark stay identical in size."
            );
        }

        // Both themes must resolve the same set of semantic colours.
        preg_match('/:root\s*\{(.*?)\n\}/s', $css, $light);
        preg_match_all('/(--color-(primary|success|warning|danger|info))[^:]*:\s*[^;]+;/i', $light[1], $lightColours);
        preg_match_all('/(--color-(primary|success|warning|danger|info))[^:]*:\s*[^;]+;/i', $dark[1], $darkColours);

        sort($lightColours[1]);
        sort($darkColours[1]);

        $this->assertSame(
            $lightColours[1],
            $darkColours[1],
            'Light and dark themes must define the same semantic colours, or a component will fall back to the wrong one.'
        );
    }

    public function test_no_template_still_carries_a_removed_radius_token(): void
    {
        // --radius-pill was dropped when the badges became boxy; a leftover
        // reference would silently fall back to the browser default.
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = File::get($file->getPathname());

            if (str_contains($source, '--radius-pill')) {
                $offenders[] = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'Templates referencing the removed --radius-pill: ' . implode(', ', $offenders));
    }

    public function test_every_stylesheet_referenced_by_a_layout_exists(): void
    {
        $missing = [];

        foreach (['layouts.app', 'layouts.guest'] as $layout) {
            $path = resource_path('views/' . str_replace('.', '/', $layout) . '.blade.php');
            $source = File::get($path);

            if (preg_match_all("/Asset::url\('css\/([^']+)'\)/", $source, $matches)) {
                foreach ($matches[1] as $sheet) {
                    if (! File::exists(public_path('css/' . $sheet))) {
                        $missing[] = "{$layout} references missing css/{$sheet}";
                    }
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }

    public function test_stylesheets_are_served_with_a_cache_busting_version(): void
    {
        /*
         * There is no bundler in this application, so nothing hashes the
         * filename for us. An unversioned stylesheet URL means every user keeps
         * the copy their browser already cached, so a CSS fix reaches nobody
         * until they happen to hard-refresh — which is how a working change
         * gets mistaken for a broken one.
         *
         * The links live in one shared partial now. They used to be copied into
         * every layout, which is how the till ended up missing a stylesheet and
         * the admin ended up without an icon: a file nobody looks at is a file
         * nobody updates.
         */
        $partial = File::get(resource_path('views/partials/head.blade.php'));

        preg_match_all('/<link rel="stylesheet" href="\{\{(.*?)\}\}">/s', $partial, $links);

        $this->assertNotEmpty($links[1], 'partials/head.blade.php has no stylesheet links.');

        foreach ($links[1] as $helper) {
            $this->assertStringContainsString(
                'Asset::url(',
                $helper,
                "The shared head links a stylesheet through {$helper}. Use Asset::url() so the "
                . 'file is revalidated on change.'
            );
        }

        foreach (['app', 'guest', 'till'] as $layout) {
            $source = File::get(resource_path("views/layouts/{$layout}.blade.php"));

            // Declaring them locally is the duplication this replaced.
            $this->assertStringNotContainsString(
                '<link rel="stylesheet"',
                $source,
                "layouts/{$layout}.blade.php declares its own stylesheet links. They belong in "
                . 'partials/head.blade.php, or the two copies will drift.'
            );

            // And it has to include the shared head, or the layout gets a
            // document with no styles at all.
            $this->assertStringContainsString(
                'partials.head',
                $source,
                "layouts/{$layout}.blade.php does not include the shared head."
            );
        }

        // And the helper must actually stamp the URL.
        \App\Support\Asset::flush();

        $url = \App\Support\Asset::url('css/base.css');

        $this->assertStringContainsString('css/base.css', $url);
        $this->assertStringContainsString('v=', $url, 'Asset::url() must append a version stamp.');

        // A file that does not exist must not get a stamp, so the 404 is honest.
        $this->assertStringNotContainsString('v=', \App\Support\Asset::url('css/does-not-exist.css'));
    }

    /**
 * Two controls sitting side by side must be the same height.
 *
 * This is the rule that gets broken over and over, and it is invisible in
 * isolation: a `md` button next to an `lg` button is only noticed once they
 * are on the same row, and by then it is a bug report rather than a test
 * failure. Asserted against the POS cart actions specifically, because that
 * row is the one a cashier looks at on every single sale.
 */
public function test_the_cart_action_buttons_share_one_height(): void
{
    $this->bootBusiness();

    $html = $this->actingAs(\App\Models\User::where('email', 'owner@example.test')->first())
        ->get(route('pos.index'))
        ->assertOk()
        ->getContent();

    // Pull the size class off each button in the cart action row. The markup
    // cannot be checked by eye at 100 lines of Blade, but a mismatch here is
    // exactly what the eye catches and the markup hides.
    $buttons = $this->buttonClasses($this->innerHtmlOfClass($html, 'pos-cart-actions'));

    $this->assertGreaterThanOrEqual(2, count($buttons), 'Expected at least two action buttons.');

    // Resolve each button's size class; an absent one falls back to .btn's
    // default, which is exactly the case that hides the mismatch.
    $sizes = array_map(function (string $class): ?string {
        return preg_match('/\bbtn-(sm|md|lg)\b/', $class, $m) === 1 ? $m[1] : null;
    }, $buttons);

    // A button with no explicit size falls back to .btn's default, so compare
    // resolved sizes rather than the literal class.
    $map = $this->variableMap($this->tokens());

    $resolve = function (?string $size) use ($map): string {
        $token = match ($size) {
            'sm' => '--control-sm',
            'lg' => '--control-lg',
            default => '--control-md',
        };

        return (string) $this->remToPx($this->resolve($this->token($map, $token), $map));
    };

    $heights = array_map($resolve, $sizes);

    $this->assertCount(
        1,
        array_unique($heights),
        'Adjacent buttons in the cart action row resolve to different heights: '
        . implode(', ', array_map(
            fn ($s, $h) => ($s ?? 'default').'='.$h.'px',
            $sizes,
            $heights
        ))
        . '. Buttons sitting next to each other must match.'
    );
}

/**
 * The POS header is the one row where a height mismatch is immediately
 * visible: a toolbar of small buttons with a toggle and a takings readout
 * dropped between them. Each element picks its height up from a different
 * rule — a size modifier, its own component class — so nothing in the markup
 * says they should agree. This asserts they do.
 */
public function test_every_control_in_the_pos_header_row_is_the_same_height(): void
{
    $this->bootBusiness();

    $html = $this->actingAs(\App\Models\User::where('email', 'owner@example.test')->first())
        ->get(route('pos.index'))
        ->assertOk()
        ->getContent();

    $row = $this->innerHtmlOfClass($html, 'pos-header-actions');
    $css = $this->tokens();
    $map = $this->variableMap($css);

    // Selector => the control it stands for, so a failure names the culprit.
    $controls = [
        '.btn-sm' => 'the header buttons',
        '.pos-toggle' => 'the credit toggle',
        '.pos-session-total' => 'the shift takings readout',
        '.form-select' => 'the warehouse picker',
    ];

    // Only check what this terminal actually rendered: the warehouse picker is
    // conditional on the branch having more than one warehouse, and asserting
    // on an absent control would pass for the wrong reason.
    $heights = [];

    foreach ($controls as $selector => $label) {
        if ($selector === '.btn-sm' && $this->buttonClasses($row) === []) {
            continue;
        }

        if ($selector === '.pos-toggle' && ! str_contains($row, 'pos-toggle')) {
            continue;
        }

        if ($selector === '.pos-session-total' && ! str_contains($row, 'pos-session-total ')) {
            continue;
        }

        if ($selector === '.form-select' && ! str_contains($row, 'form-select')) {
            continue;
        }

        $height = $this->heightOfSelector($css, $selector, $map);

        $this->assertNotNull(
            $height,
            "{$label} ({$selector}) declares no height, so it cannot be held to the row."
        );

        $heights[$label] = $height;
    }

    $this->assertGreaterThanOrEqual(
        2,
        count($heights),
        'The header row needs at least two controls before a height rule means anything.'
    );

    $this->assertCount(
        1,
        array_unique($heights),
        'The POS header row mixes heights: '
        . implode(', ', array_map(fn ($l, $h) => "{$l}={$h}px", array_keys($heights), $heights))
        . '. Controls sitting next to each other must match.'
    );
}

public function test_a_secondary_button_is_legible_in_both_themes(): void
{
    $css = File::get(public_path('css/buttons.css'));

    // Regression guard: `.btn-secondary` once used `--color-secondary` as a
    // fill, but that token is a TEXT colour — and its dark value is near-white,
    // so the button rendered white-on-white. It must use surfaces.
    preg_match('/\.btn-secondary\s*\{([^}]*)\}/s', $css, $rule);
    $this->assertNotEmpty($rule, '.btn-secondary must exist.');

    $this->assertStringContainsString('var(--bg-surface)', $rule[1]);
    $this->assertStringNotContainsString('color: #fff', $rule[1]);

    // And no button may hard-code white on a saturated fill; the theme owns it.
    $this->assertDoesNotMatchRegularExpression(
        '/\.btn-(primary|secondary|success|danger)[^{]*\{[^}]*color:\s*#fff/s',
        $css,
        'Buttons must take their on-fill text from a token so dark mode can invert it.'
    );
}

public function test_a_browser_page_applies_the_tokens(): void
    {
        $this->bootBusiness();
        $this->actingAs(\App\Models\User::where('email', 'owner@example.test')->first())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('css/base.css', false);
    }

    /* ======================================================================
       The till

       The terminal is a separate surface with its own ergonomics, so the rules
       that keep it usable are asserted separately from the admin's.
       ====================================================================== */

    public function test_the_terminal_has_its_own_stylesheet_wired_into_the_layout(): void
    {
        $this->assertFileExists(public_path('css/pos.css'));

        // Loaded by the till layout through the shared head's $extraStyles slot
        // rather than declared inline: the till has distinct ergonomics and its
        // own sheet, and an unversioned URL for it would reach nobody.
        $till = File::get(resource_path('views/layouts/till.blade.php'));

        $this->assertStringContainsString('css/pos.css', $till, 'The till layout never asks for the till stylesheet.');
        $this->assertStringContainsString('extraStyles', $till);

        $head = File::get(resource_path('views/partials/head.blade.php'));

        $this->assertStringContainsString(
            '$extraStyles',
            $head,
            'The shared head has no extra-styles slot, so a layout cannot add one without '
            . 'hand-rolling the whole head.'
        );

        $this->assertMatchesRegularExpression(
            '/Asset::url\(\$\w+\)/',
            $head,
            'The shared head must render the extra sheet through the versioned helper.'
        );

        // And the admin must NOT pay for it.
        $admin = File::get(resource_path('views/layouts/app.blade.php'));

        $this->assertStringNotContainsString(
            'css/pos.css',
            $admin,
            'The admin loads the till stylesheet. The till has its own layout now, so the admin '
            .'does not need them.'
        );
    }

    public function test_the_till_shell_fills_the_viewport_and_never_scrolls_as_a_page(): void
    {
        $css = File::get(public_path('css/pos.css'));

        // A cashier must never scroll to find the total. The shell owns the
        // viewport outright; only the product grid and the cart scroll inside.
        //
        // dvh, not vh, and not a calc() against the topbar: the till has no
        // topbar, so subtracting its height leaves a dead band along the bottom
        // of every till.
        $this->assertMatchesRegularExpression(
            '/\.pos-shell\s*\{[^}]*height:\s*100dvh/s',
            $css,
            '.pos-shell must claim the viewport outright.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\.pos-shell\s*\{[^}]*calc\(100vh/s',
            $css,
            '.pos-shell still subtracts the topbar height. The till has no topbar.'
        );

        // The document itself must not scroll, or a stray focus can scroll the
        // page and take the total off screen.
        $this->assertMatchesRegularExpression(
            '/\.pos-body\s*\{[^}]*overflow:\s*hidden/s',
            $css,
            '.pos-body must not scroll: the shell owns the viewport.'
        );
    }

    public function test_the_total_is_the_largest_number_in_the_till(): void
    {
        $css = File::get(public_path('css/pos.css'));
        $map = $this->variableMap($this->tokens());

        preg_match('/\.pos-total-value\s*\{[^}]*font-size:\s*([^;]+);/s', $css, $total);
        $this->assertNotEmpty($total, '.pos-total-value must declare a font size.');

        $totalPx = $this->remToPx($this->resolve($total[1], $map));

        /*
         * The rule is not "bigger than every type step in the system" — it is
         * "bigger than every other figure on this panel". Comparing against the
         * other cart selectors states the real constraint and does not break the
         * moment somebody adds a display size for some unrelated heading.
         */
        $rivals = [
            '.pos-money-label',
            '.pos-money-value',
            '.pos-total-label',
            '.pos-total-count',
            '.pos-line-name',
            '.pos-line-total',
        ];

        foreach ($rivals as $selector) {
            preg_match('/' . preg_quote($selector, '/') . '\s*\{[^}]*font-size:\s*([^;]+);/s', $css, $found);

            $this->assertNotEmpty($found, "{$selector} must declare a font size.");

            $px = $this->remToPx($this->resolve($found[1], $map));

            $this->assertGreaterThan(
                $px,
                $totalPx,
                "The cart total ({$totalPx}px) must be larger than {$selector} ({$px}px), "
                . 'or a cashier reads the tax line instead.'
            );
        }
    }

    public function test_the_cart_column_is_a_fixed_width(): void
    {
        $css = File::get(public_path('css/pos.css'));

        // A cart whose width breathes with its contents moves the Charge button
        // between sales, and a cashier reaching for it cannot land twice.
        $this->assertMatchesRegularExpression(
            '/\.pos-cart\s*\{[^}]*width:\s*([\d.]+rem)/s',
            $css,
            '.pos-cart must declare a fixed width.'
        );

        preg_match('/\.pos-cart\s*\{[^}]*width:\s*([\d.]+rem)/s', $css, $match);
        $this->assertLessThanOrEqual(30, (float) $match[1], 'The cart column must stay narrow enough to leave room for the grid.');
    }

    public function test_adjacent_product_tiles_have_one_shared_height(): void
    {
        $css = File::get(public_path('css/pos.css'));

        // The grid is the reason the original terminal looked ragged: without
        // this, a card with no SKU line is shorter than its neighbour and the
        // price row lands at a different height on every tile.
        $this->assertMatchesRegularExpression(
            '/\.pos-product\s*\{[^}]*height:\s*100%/s',
            $css,
            '.pos-product must fill its grid track so every tile is the same height.'
        );
        $this->assertMatchesRegularExpression(
            '/\.pos-product-body\s*\{[^}]*flex:\s*1 1 auto/s',
            $css,
            '.pos-product-body must flex so the price pins to the bottom of every card.'
        );
    }

    public function test_the_till_uses_only_logical_properties_for_direction_sensitive_offsets(): void
    {
        // A transform is not mirrored by `dir`, and a `left:`/`right:` offset
        // is not either. Either one silently breaks the layout the moment an
        // administrator adds an RTL language.
        $offenders = [];

        foreach (File::lines(public_path('css/pos.css')) as $number => $line) {
            $line = trim($line);

            if (str_starts_with($line, '/*') || str_starts_with($line, '*')) {
                continue;
            }

            // `inset-inline-*`, `margin-inline-*`, `padding-inline-*` and
            // `border-inline-*` are all fine; bare `left`/`right` are not.
            if (preg_match('/(?<![\w-])(?:left|right)\s*:/', $line)) {
                $offenders[] = "pos.css:{$number} {$line}";
            }

            if (preg_match('/translateX\(/', $line) && ! str_contains($line, '/*')) {
                $offenders[] = "pos.css:{$number} translateX() is not mirrored by dir: {$line}";
            }
        }

        $this->assertSame([], $offenders, "Physical offsets in the till:\n" . implode("\n", $offenders));
    }

    public function test_nothing_that_can_interrupt_a_sale_sits_outside_the_till_shell(): void
    {
        // Anything rendered above the shell pushes it below the fold and
        // reintroduces the page scroll the till layout exists to remove. An
        // alert has to cost the product grid its height, not the whole page's.
        $source = File::get(resource_path('views/pos/terminal.blade.php'));

        $shellAt = strpos($source, 'class="pos-shell"');
        $this->assertNotFalse($shellAt, 'The terminal must have a shell.');

        // The only markup allowed before the shell is the flash partial.
        $before = substr($source, 0, $shellAt);

        $this->assertStringNotContainsString(
            'pos-alerts',
            $before,
            'Blocking alerts must render inside the till shell, not above it.'
        );
        $this->assertStringNotContainsString(
            '<x-ui.alert',
            $before,
            'No alert may be emitted outside the till shell.'
        );
    }

    public function test_every_product_tile_reserves_its_code_row(): void
    {
        // A tile whose SKU line is conditionally rendered is a line shorter
        // than its neighbour, and a grid of ragged tiles is the defect this
        // card layout exists to prevent.
        $view = File::get(resource_path('views/pos/terminal.blade.php'));
        $script = File::get(public_path('js/pos.js'));

        $this->assertMatchesRegularExpression(
            '/<span class="pos-product-sku">\s*\{\{/s',
            $view,
            'The server-rendered tile must always emit a code row, even when empty.'
        );

        $this->assertStringContainsString(
            "pos-product-sku\"></span>",
            $script,
            'The client-rendered tile must always emit a code row too, or the grid '
            . 'changes height the first time the cashier searches.'
        );
    }

    public function test_the_till_boot_payload_is_complete_for_the_client(): void
    {
        // The terminal must render server-side and stay usable if the script
        // never runs, so anything pos.js requires unconditionally has to be in
        // the payload rather than fetched.
        $this->bootBusiness();

        $html = $this->actingAs(\App\Models\User::where('email', 'owner@example.test')->first())
            ->get(route('pos.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="pos-bootstrap"', $html);

        preg_match('/<script id="pos-bootstrap" type="application\/json">(.*?)<\/script>/s', $html, $match);
        $this->assertNotEmpty($match, 'The bootstrap payload is missing or malformed.');

        $boot = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);
        $this->assertIsArray($boot, 'The bootstrap payload is not valid JSON: ' . json_last_error_msg());

        foreach ([
            'products', 'categories', 'customers', 'paymentMethods',
            'searchUrl', 'customersUrl', 'saleUrl', 'syncUrl', 'csrf',
            'currency', 'labels', 'offline',
        ] as $key) {
            $this->assertArrayHasKey($key, $boot, "The terminal bootstrap payload is missing `{$key}`.");
        }

        // Every currency slot pos.js formats money through.
        foreach (['symbol', 'position', 'decimals', 'thousand', 'decimal'] as $key) {
            $this->assertArrayHasKey($key, $boot['currency']);
        }
    }
}
