<?php

namespace App\Http\Middleware;

use App\Models\Language;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

/**
 * Apply the interface locale on every request.
 *
 * The switcher in the topbar only writes to the session and the user — without
 * this middleware nothing ever read it back, so switching to Bengali appeared
 * to do nothing: each new page load silently fell back to English.
 *
 * Resolution order: the session choice, then the user's saved choice, then the
 * default language. The document direction rides along, so an RTL language is
 * laid out correctly from the first byte, not one request too late.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $code = Session::get('locale');

        if (! $code && $request->user()) {
            $code = $request->user()->locale;
        }

        $language = $code
            ? Language::where('code', $code)->where('is_active', true)->first()
            : null;

        $language ??= Language::defaultLanguage();

        if ($language) {
            // A regional locale (bn_BD) falls back to its base file when no
            // bn_BD.json exists — translations ship as bn.json.
            $locale = $language->locale ?: $language->code;
            if (! is_file(lang_path($locale . '.json'))) {
                $locale = $language->code;
            }

            App::setLocale($locale);
            Session::put('locale', $language->code);
            Session::put('direction', $language->direction);
        }

        return $next($request);
    }
}
