<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ThemeController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $theme = $request->input('theme') === 'dark' ? 'dark' : 'light';

        $request->session()->put('theme', $theme);
        $request->user()->update(['theme' => $theme]);

        return back();
    }
}
