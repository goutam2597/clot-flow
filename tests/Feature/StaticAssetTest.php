<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Static assets that every page depends on.
 *
 * public/favicon.ico was a zero-byte file. A browser given an empty icon shows
 * nothing for it, so it fell back to whatever it had cached - and because it had
 * once rendered a Laravel error page, that became the icon for every tab in the
 * application, long after the error was fixed. The screenshot that prompted this
 * was a stale red Laravel logo on a page that was serving perfectly.
 *
 * An empty file is worse than a missing one: a 404 makes the browser ask again,
 * an empty 200 makes it remember the answer.
 */
class StaticAssetTest extends TestCase
{
    public function test_every_public_file_is_not_empty(): void
    {
        $empty = [];

        foreach (File::files(public_path()) as $file) {
            // The storage symlink reports a size of 0 because a link has no
            // size of its own, and isDir() is false for it on Windows. Neither
            // is an empty file, and asserting on them fails on the one entry
            // that is supposed to be there.
            if ($file->isDir() || $file->isLink()) {
                continue;
            }

            if ($file->getSize() === 0) {
                $empty[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $empty,
            "These public assets are zero bytes. An empty 200 tells the browser to keep "
            ."remembering whatever it showed last time:\n  ".implode("\n  ", $empty)
        );
    }

    public function test_the_favicon_is_a_real_icon(): void
    {
        $this->assertFileExists(public_path('favicon.svg'));

        $svg = File::get(public_path('favicon.svg'));

        $this->assertStringContainsString('<svg', $svg);
        $this->assertGreaterThan(100, strlen($svg), 'The favicon is too small to be a real icon.');

        // A stale favicon.ico alongside a real SVG is not a safety net, it is a
        // second answer: browsers pick between them unpredictably.
        $this->assertFileDoesNotExist(
            public_path('favicon.ico'),
            'favicon.ico alongside favicon.svg gives the browser two answers. Pick one.'
        );
    }

    public function test_every_layout_declares_the_icon(): void
    {
        // The icon is declared once, in the shared head, because two copies of
        // a head is how the admin ended up shipping without one.
        $head = File::get(resource_path('views/partials/head.blade.php'));

        $this->assertStringContainsString('rel="icon"', $head);
        $this->assertStringContainsString('favicon.svg', $head);
        $this->assertStringContainsString('Asset::url(', $head, 'The icon must be versioned too.');

        foreach (['app', 'guest', 'till'] as $layout) {
            $source = File::get(resource_path("views/layouts/{$layout}.blade.php"));

            $this->assertStringContainsString(
                'partials.head',
                $source,
                "layouts/{$layout}.blade.php does not include the shared head, so it gets a "
                .'document with no icon and no styles.'
            );

            $this->assertStringNotContainsString(
                'rel="icon"',
                $source,
                "layouts/{$layout}.blade.php declares its own icon. Two declarations means one "
                .'of them goes stale.'
            );
        }
    }

    public function test_the_service_worker_caches_only_assets_that_exist(): void
    {
        $sw = File::get(public_path('sw.js'));

        preg_match_all("/^\s*'(\/[^']+)',$/m", $sw, $matches);

        $missing = [];

        foreach ($matches[1] as $path) {
            // Skip query strings and anything that is a route, not a file.
            if (str_contains($path, '?') || ! str_ends_with($path, '.css') && ! str_ends_with($path, '.js')) {
                continue;
            }

            if (! File::exists(public_path(ltrim($path, '/')))) {
                $missing[] = $path;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "The service worker precaches files that do not exist. cache.addAll() rejects the "
            ."whole batch on one miss, so the worker never installs:\n  ".implode("\n  ", $missing)
        );
    }
}
