<!DOCTYPE html>
{{--
    The till.

    No sidebar, no topbar, no page padding. A point of sale is a single-purpose
    screen that a person stands in front of for four hours: every strip of
    chrome is space the product grid cannot use, and a route back to the admin
    is one stray keystroke away from being taken mid-sale. The way out is the
    browser's own back button and the account menu on the header row.

    Everything else - the palette, the fonts, the direction handling, the theme
    - is the same document as everywhere else, which is why the head is a shared
    partial rather than a second copy.
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
      data-theme="{{ auth()->check() && auth()->user()->theme ? auth()->user()->theme : (session('theme', 'light')) }}"
      class="pos-document">
<head>
    @include('partials.head', ['title' => $title ?? null, 'extraStyles' => 'css/pos.css'])
</head>

{{-- No skip link: there is no navigation to skip to. The first control on the
     screen is the search field, and pos.js focuses it on load. --}}
{{--
    The body class is pos-viewport and must stay distinct from every class used
    inside the page. It was once pos-body, which is also the two-column row's
    class - so the row's `display: flex` turned the whole document into a flex
    row, the main stopped stretching, and the shell sized to its content
    (1542px inside a 1700px viewport) instead of filling the window.
    TillLayoutTest guards the general case: no class on this tag may establish
    a flex container.
--}}
<body class="pos-viewport">
    <main class="pos-main" id="main-content">
        {{ $slot }}
    </main>

    <div class="toast-container" id="toast-container"></div>

    <script src="{{ \App\Support\Asset::url('js/app.js') }}"></script>
    <script src="{{ \App\Support\Asset::url('js/select.js') }}"></script>
    @stack('scripts')
</body>
</html>
