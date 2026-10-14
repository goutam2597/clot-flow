<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Structural guards that catch whole classes of breakage before a page is
 * opened in a browser.
 *
 * A truncated controller or a half-written Blade file fails at runtime, on one
 * screen, for one user. These checks fail in CI instead, naming the file.
 */
class ApplicationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    protected function bladeFiles(): array
    {
        return File::allFiles(resource_path('views'));
    }

    public function test_every_blade_template_compiles(): void    {
        $compiler = app('blade.compiler');
        $failures = [];

        foreach ($this->bladeFiles() as $file) {
            $relative = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $file->getPathname());

            try {
                $compiled = $compiler->compileString(File::get($file->getPathname()));
            } catch (\Throwable $e) {
                $failures[] = "{$relative} — " . $e->getMessage();
                continue;
            }

            // A template that compiles to unbalanced PHP is the common result of
            // a file that was written but not finished (a missing @endif, or a
            // component tag that is never closed).
            $check = tempnam(sys_get_temp_dir(), 'blade');
            file_put_contents($check, $compiled);

            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($check) . ' 2>&1', $output, $status);
            @unlink($check);

            if ($status !== 0) {
                $failures[] = "{$relative} — " . trim(implode(' ', $output));
            }
        }

        $this->assertSame([], $failures, "Blade templates failed to compile:\n" . implode("\n", $failures));
    }

    public function test_every_route_resolves_to_a_public_controller_method(): void
    {
        $broken = [];

        foreach (app('router')->getRoutes() as $route) {
            $action = $route->getActionName();

            if ($action === 'Closure' || ! str_contains($action, 'Controller')) {
                continue;
            }

            // Laravel stores an invokable controller as the bare class name in
            // `uses`, and as `Class@__invoke` in `controller`. Reading the bare
            // name and chopping the namespace off the end - as this did -
            // leaves `App\Http\Controllers` with no class on it, which is how a
            // perfectly valid invokable route gets reported as broken.
            [$class, $method] = str_contains($action, '@')
                ? explode('@', $action, 2)
                : [$action, '__invoke'];

            if (! class_exists($class)) {
                $broken[] = "Missing controller {$class} for {$route->uri()}";
                continue;
            }

            if (! method_exists($class, $method)) {
                $broken[] = "{$class}::{$method}() does not exist ({$route->uri()})";
                continue;
            }

            if (! (new ReflectionMethod($class, $method))->isPublic()) {
                $broken[] = "{$class}::{$method}() is not public ({$route->uri()})";
            }
        }

        $this->assertSame([], $broken, "Routes pointing at nothing:\n" . implode("\n", $broken));
    }

    public function test_every_view_referenced_by_a_controller_exists(): void
    {
        $missing = [];
        $pattern = '/\bview\(\s*[\'"]([^\'"]+)[\'"]/';

        foreach (File::allFiles(app_path()) as $file) {
            $source = File::get($file->getPathname());

            if (! preg_match_all($pattern, $source, $matches)) {
                continue;
            }

            $relative = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());

            foreach ($matches[1] as $view) {
                $path = resource_path('views/' . str_replace('.', '/', $view));

                if (! File::exists($path . '.blade.php') && ! File::exists($path . '.php')) {
                    $missing[] = "{$relative} renders missing view [{$view}]";
                }
            }
        }

        $this->assertSame([], $missing, "Views referenced but not built:\n" . implode("\n", $missing));
    }

    public function test_every_blade_component_used_resolves(): void
    {
        $available = [];

        foreach (File::allFiles(resource_path('views/components')) as $file) {
            $relative = substr($file->getPathname(), strlen(resource_path('views/components')) + 1);
            $relative = preg_replace('/\.blade\.php$/', '', $relative);
            $available[str_replace([DIRECTORY_SEPARATOR, '/'], '.', $relative)] = true;
        }

        $missing = [];
        // The first segment may contain hyphens (`<x-app-layout>`), as may later
        // ones (`<x-ui.button>`). `slot` is Blade's own construct, not a component.
        $pattern = '/<x-([a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*)/';

        foreach ($this->bladeFiles() as $file) {
            $source = File::get($file->getPathname());

            if (! preg_match_all($pattern, $source, $matches)) {
                continue;
            }

            $relative = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $file->getPathname());

            foreach ($matches[1] as $component) {
                if ($component === 'slot' || isset($available[$component])) {
                    continue;
                }

                $missing[] = "{$relative} uses <x-{$component}> which does not exist";
            }
        }

        $this->assertSame([], $missing, "Unresolvable components:\n" . implode("\n", array_unique($missing)));
    }

    public function test_no_controller_is_left_with_a_generation_marker(): void
    {
        $truncated = [];

        foreach (File::allFiles(app_path()) as $file) {
            $source = File::get($file->getPathname());

            if (str_contains($source, '__PART_')) {
                $truncated[] = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $truncated, 'Files still carrying a build marker: ' . implode(', ', $truncated));
    }

    public function test_no_hardcoded_currency_symbol_leaks_into_the_views(): void
    {
        // Money must be formatted through Formatter so the active currency's
        // symbol, separators and decimal places are respected.
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = File::get($file->getPathname());

            if (preg_match('/\{\{\s*[\'"]\$[\'"]/', $source)) {
                $offenders[] = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], array_unique($offenders), 'Views printing money without the formatter.');
    }

    public function test_every_model_declares_its_fillable_columns(): void
    {
        // Mass assignment is the single biggest source of privilege and data
        // integrity bugs in a Laravel app; an empty guard is worth catching.
        $unguarded = [];

        foreach (File::files(app_path('Models')) as $file) {
            $name = $file->getFilenameWithoutExtension();
            $class = 'App\\Models\\' . $name;

            if (! class_exists($class)) {
                continue;
            }

            $model = new $class();

            if ($model->getFillable() === [] && $model->getGuarded() !== ['*']) {
                $unguarded[] = $name;
            }
        }

        $this->assertSame([], $unguarded, 'Models with no fillable allow-list: ' . implode(', ', $unguarded));
    }

    public function test_blade_pushes_appear_before_the_layout_component_closes(): void
    {
        // The layout renders its @stack the moment the component resolves, so a
        // @push written after the closing tag arrives too late: the page loads
        // with no JavaScript at all, and nothing else in the suite notices.
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = File::get($file->getPathname());

            if (! str_contains($source, '@push(')) {
                continue;
            }

            $relative = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $file->getPathname());

            // Where does the layout component start?
            if (! preg_match('/<x-(app-layout|layouts\.app|guest-layout)/', $source, $open, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $afterOpen = strpos($source, '@push(');

            if ($afterOpen === false) {
                continue;
            }

            // Find the matching closing tag for the component.
            $tag = $open[0][0];
            $close = '</x-' . preg_replace('/^<x-([a-zA-Z0-9_.-]+).*$/', '$1', $tag) . '>';
            $closeAt = strpos($source, $close);

            if ($closeAt !== false && $afterOpen > $closeAt) {
                $offenders[] = "{$relative} pushes to a stack after {$close} (line " . (substr_count(substr($source, 0, $afterOpen), "\n") + 1) . ')';
            }
        }

        $this->assertSame([], $offenders, "@push written after the layout closes:\n" . implode("\n", $offenders));
    }

    public function test_the_pos_terminal_actually_loads_its_scripts(): void
    {
        $this->bootBusiness();
        $this->actingAs(\App\Models\User::where('email', 'owner@example.test')->first())
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('js/pos.js', false)
            ->assertSee('js/offline.js', false)
            ->assertSee('id="pos-bootstrap"', false);
    }

    public function test_the_purchase_editor_actually_loads_its_script(): void
    {
        $this->bootBusiness();
        $this->actingAs(\App\Models\User::where('email', 'owner@example.test')->first())
            ->get(route('purchases.create'))
            ->assertOk()
            ->assertSee('js/purchase.js', false)
            ->assertSee('id="purchase-bootstrap"', false);
    }
}
