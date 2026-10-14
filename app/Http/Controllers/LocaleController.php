<?php

namespace App\Http\Controllers;

use App\Models\Language;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

class LocaleController extends Controller
{
    /**
     * Switch the interface language.
     *
     * The choice is persisted on the user as well as the session so it survives
     * a new sign-in, and on the request so the very next render already uses it.
     */
    public function switch(Request $request, string $code): RedirectResponse
    {
        $language = Language::where('code', $code)->where('is_active', true)->first();

        if (! $language) {
            return back()->with('error', __('That language is not available.'));
        }

        App::setLocale($language->locale ?: $language->code);
        Session::put('locale', $language->code);

        // Direction follows the language, so an administrator adding an RTL
        // language gets a correct layout without touching any template.
        Session::put('direction', $language->direction);

        if ($request->user()) {
            $request->user()->forceFill(['locale' => $language->code])->save();
        }

        return back();
    }
}
