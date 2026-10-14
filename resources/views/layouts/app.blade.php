<!DOCTYPE html>
{{--
    The admin shell: a persistent sidebar, a topbar, and the page.

    The point of sale does NOT use this. It has its own layout with no chrome,
    because a till is a single-purpose screen and a 16rem sidebar plus a topbar
    is a third of a 15" counter display spent on navigation nobody needs mid-sale.
--}}
@php
    $activeLanguage = \App\Models\Language::where('code', app()->getLocale())->first()
        ?? \App\Models\Language::defaultLanguage();
    $direction = session('direction')
        ?? ($activeLanguage?->direction ?? 'ltr');
    $documentDirection = $direction === 'rtl' ? 'rtl' : 'ltr';
@endphp
<html lang="{{ str_replace('_', '-', $activeLanguage?->locale ?? app()->getLocale()) }}"
      dir="{{ $documentDirection }}"
      data-theme="{{ auth()->check() && auth()->user()->theme ? auth()->user()->theme : (session('theme', 'light')) }}">
<head>
    @include('partials.head', ['title' => $title ?? null, 'extraStyles' => $extraStyles ?? null])
</head>
<body>
    <a href="#main-content" class="skip-link">{{ __('Skip to main content') }}</a>

    <div class="app" id="app">
        @include('partials.sidebar')

        <div class="main">
            @include('partials.topbar')

            <main class="content" id="main-content">
                {{ $slot }}
            </main>
        </div>
    </div>

    <div class="toast-container" id="toast-container"></div>

    {{-- Same reasoning as the stylesheets: a script edited without a new URL is
         one the browser never re-fetches, so the fix lands in the file on disk
         but not on the screen. select.js carries the custom combobox and must
         load after app.js, which owns the shared helpers. --}}
    <script src="{{ \App\Support\Asset::url('js/app.js') }}"></script>
    <script src="{{ \App\Support\Asset::url('js/bulk.js') }}"></script>
    <script src="{{ \App\Support\Asset::url('js/select.js') }}"></script>
    @stack('scripts')
</body>
</html>
