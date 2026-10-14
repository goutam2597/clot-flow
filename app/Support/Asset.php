<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Versioned asset URLs.
 *
 * `asset('css/base.css')` returns the same string on every deploy, so a browser
 * that already has the file will happily keep serving its cached copy. For an
 * application with no build step there is no manifest and no content hash to
 * lean on, which means a stylesheet fix can sit on disk, look correct, and not
 * reach the user at all.
 *
 * This appends the file's last-modified time as a query parameter. It costs
 * nothing, needs no Node toolchain, and turns "the CSS is not updating" from a
 * debugging session into an ordinary cache revalidation.
 *
 * It is deliberately not wired over `asset()` globally: an explicit call site
 * says "this file's identity matters", and the files that never change (the
 * service worker, a favicon) keep their stable URLs.
 */
class Asset
{
    /** @var array<string, string> Memoised URL => url, per request. */
    private static array $resolved = [];

    public static function url(string $path): string
    {
        if (isset(self::$resolved[$path])) {
            return self::$resolved[$path];
        }

        $path = '/'.ltrim($path, '/');
        $absolute = public_path(ltrim($path, '/'));

        // Only stamp files that actually exist. A missing asset should 404
        // loudly rather than being silently pinned to a version that does not
        // exist either.
        $url = File::exists($absolute)
            ? asset($path).'?v='.filemtime($absolute)
            : asset($path);

        return self::$resolved[$path] = $url;
    }

    /**
     * Clear the memo. Only useful in tests, where the same process writes a
     * file and then asks for its URL again.
     */
    public static function flush(): void
    {
        self::$resolved = [];
    }
}