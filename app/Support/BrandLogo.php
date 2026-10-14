<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The business logo, uploaded through settings.
 *
 * Two kinds, stored under branding/ on the public disk:
 *
 *  - SVG, the preferred one. It is sanitised (a file about to be pasted into
 *    every authenticated page has no business carrying scripts or external
 *    references) and INLINED into the markup: a real <svg> inherits the text
 *    colour around it, so a monochrome mark reads correctly in light and dark
 *    mode. An <img> could never do that.
 *
 *  - PNG. Rendered through a plain <img> at its exact uploaded colours — a
 *    raster cannot change its ink with the theme, by definition.
 *
 * One fixed filename per kind: a logo replaces a logo, nothing accumulates.
 */
class BrandLogo
{
    public const SETTING_KEY = 'brand_logo';
    public const SETTING_GROUP = 'general';
    public const DISK = 'public';
    public const FILE_SVG = 'branding/logo.svg';
    public const FILE_PNG = 'branding/logo.png';

    /** The stored path, or null when no usable logo is set. */
    public static function path(): ?string
    {
        $path = Setting::get(self::SETTING_KEY, '', self::SETTING_GROUP);

        if ($path && Storage::disk(self::DISK)->exists($path)) {
            return $path;
        }

        return null;
    }

    /** 'svg' or 'png', or null when no logo is set. */
    public static function type(): ?string
    {
        $path = self::path();

        return $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : null;
    }

    /** Public URL of the stored file — for the PNG <img> and previews. */
    public static function url(): ?string
    {
        $path = self::path();

        return $path ? Storage::disk(self::DISK)->url($path) : null;
    }

    /**
     * Inline SVG markup for a stored SVG logo, ready for `{!! !!}`.
     * Null when no logo is set or the logo is a PNG.
     */
    public static function svg(): ?string
    {
        if (self::type() !== 'svg') {
            return null;
        }

        return self::themeAware(Storage::disk(self::DISK)->get(self::FILE_SVG) ?? '');
    }

    /**
     * Store an uploaded logo and clear any previous one, of either kind.
     *
     * @param  string  $kind  'svg' or 'png'
     */
    public static function storeFrom(string $realPath, string $kind): void
    {
        $kind = strtolower($kind) === 'png' ? 'png' : 'svg';

        if ($kind === 'svg') {
            $contents = file_get_contents($realPath);

            if ($contents === false || ! str_contains(strtolower($contents), '<svg')) {
                throw new \InvalidArgumentException('Not an SVG file.');
            }
        }

        // Both candidates go: switching kinds must not leave a stale
        // logo.png silently winning over the new logo.svg, or vice versa.
        Storage::disk(self::DISK)->delete([self::FILE_SVG, self::FILE_PNG]);
        Storage::disk(self::DISK)->makeDirectory(dirname(self::FILE_SVG));

        $file = $kind === 'png' ? self::FILE_PNG : self::FILE_SVG;
        $contents = $kind === 'png'
            ? file_get_contents($realPath)
            : self::clean((string) file_get_contents($realPath));

        Storage::disk(self::DISK)->put($file, $contents);

        Setting::set(self::SETTING_KEY, $file, self::SETTING_GROUP);
    }

    public static function delete(): void
    {
        Storage::disk(self::DISK)->delete([self::FILE_SVG, self::FILE_PNG]);
        Setting::set(self::SETTING_KEY, '', self::SETTING_GROUP);
    }

    /**
     * Strip everything that makes an uploaded SVG executable or remote.
     */
    public static function clean(string $svg): string
    {
        // Prolog, doctype and comments: parse overhead and XXE bait, none of
        // it visible.
        $svg = preg_replace('/<\?xml.*?\?>/is', '', $svg);
        $svg = preg_replace('/<!DOCTYPE.*?>/is', '', $svg);
        $svg = preg_replace('/<!--.*?-->/s', '', $svg);

        // Element types a brand mark has no honest use for.
        $svg = preg_replace(
            '#<(script|foreignObject|iframe|object|embed|audio|video)\b[^>]*>.*?</\1\s*>|<(script|foreignObject|iframe|object|embed|audio|video)\b[^>]*/?>#is',
            '',
            $svg
        );

        // Inline event handlers.
        $svg = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $svg);
        $svg = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', $svg);

        // javascript: URLs.
        $svg = preg_replace('/(href|xlink:href)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '', $svg);

        // External references — the file must stand alone wherever it lands.
        $svg = preg_replace('/(href|xlink:href)\s*=\s*("|\')\s*(https?:)?\/\/[^"\']*\2/i', '', $svg);

        return trim((string) $svg);
    }

    /**
     * Point monochrome artwork at currentColor so the theme drives its ink.
     *
     * A logo that names exactly one colour — or none — is recoloured to
     * currentColor; one with no colours at all paints SVG-default black,
     * which would vanish on a dark surface, so the root inherits too. A logo
     * with several distinct colours is left untouched: rewriting its palette
     * would be destruction, and a full-colour mark is expected to carry its
     * own palette everywhere.
     */
    public static function themeAware(string $svg): string
    {
        if (Str::contains($svg, 'currentColor')) {
            return $svg;
        }

        preg_match_all('/\b(fill|stroke)\s*=\s*("|\')([^"\']*)\2/i', $svg, $attrColours);
        preg_match_all('/(?:fill|stroke)\s*:\s*([^;"\'}]+)/i', $svg, $styleColours);

        $paints = array_merge($attrColours[3] ?? [], $styleColours[1] ?? []);
        $paints = array_values(array_unique(array_filter(
            array_map(fn (string $c): string => strtolower(trim($c)), $paints),
            fn (string $c): bool => $c !== '' && ! in_array($c, ['none', 'transparent', 'inherit'], true)
        )));

        if (empty($paints)) {
            return preg_replace(
                '/<svg\b([^>]*)>/i',
                '<svg$1 fill="currentColor">',
                $svg,
                1
            ) ?? $svg;
        }

        if (count($paints) !== 1) {
            return $svg;
        }

        $only = preg_quote($paints[0], '/');

        $svg = preg_replace(
            '/(\bfill|\bstroke)\s*=\s*("|\')\s*' . $only . '\s*\2/i',
            '$1="currentColor"',
            $svg
        );

        $svg = preg_replace(
            '/((?:\bfill|\bstroke)\s*:\s*)' . $only . '/i',
            '$1currentColor',
            $svg
        );

        // A root without a viewBox cannot be resized by CSS; give it one from
        // its own width/height so the partial can scale it.
        if (! Str::contains($svg, 'viewBox') && preg_match('/<svg[^>]*>/i', $svg, $root)) {
            if (preg_match('/width\s*=\s*"([\d.]+)/i', $root[0], $w) && preg_match('/height\s*=\s*"([\d.]+)/i', $root[0], $h)) {
                $patched = preg_replace(
                    '/<svg/i',
                    '<svg viewBox="0 0 ' . rtrim(rtrim($w[1], '.'), '.') . ' ' . rtrim(rtrim($h[1], '.'), '.') . '"',
                    $svg,
                    1
                );
                $svg = $patched ?? $svg;
            }
        }

        // Fixed dimensions are the container's business now.
        $svg = preg_replace_callback(
            '/<svg\b[^>]*>/i',
            fn (array $root): string => (string) preg_replace(
                '/\s(width|height)\s*=\s*("[^"]*"|\'[^\']*\')/i',
                '',
                $root[0]
            ),
            $svg,
            1
        );

        return (string) $svg;
    }
}
