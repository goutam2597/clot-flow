{{--
    The document head, shared by every layout.

    Two layouts exist: the admin shell (sidebar plus topbar) and the till (no
    chrome at all). They used to be two copies of this block, which is how the
    till ended up loading the admin styles and the admin ended up shipping
    without a favicon. One file, one description of the document.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

{{-- Declared, and versioned, because an undeclared icon leaves the browser to
     guess - and a browser that once rendered an error page keeps showing that
     page's icon for the tab long after the error is gone. --}}
<link rel="icon" href="{{ \App\Support\Asset::url('favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ \App\Support\Asset::url('favicon.svg') }}">

<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? config('app.name', 'Meridian POS') }}</title>

{{--
    Every stylesheet goes through Asset::url() so it carries a modification
    stamp. There is no bundler here to hash the filename, and an unversioned
    asset() means a user's browser keeps the copy it already has - a CSS fix
    that never reaches anyone is worse than no fix at all.
--}}
<link rel="stylesheet" href="{{ \App\Support\Asset::url('css/base.css') }}">
<link rel="stylesheet" href="{{ \App\Support\Asset::url('css/buttons.css') }}">
<link rel="stylesheet" href="{{ \App\Support\Asset::url('css/forms.css') }}">
<link rel="stylesheet" href="{{ \App\Support\Asset::url('css/components.css') }}">
<link rel="stylesheet" href="{{ \App\Support\Asset::url('css/layout.css') }}">
<link rel="stylesheet" href="{{ \App\Support\Asset::url('css/rtl-responsive.css') }}">

@php
    /*
     * $extraStyles may be one path or a list of them.
     *
     * The till adds the terminal sheet; the guest layout adds auth.css.
     * Accepting both means neither has to hand-roll its own head to get a
     * second stylesheet, which is exactly what happened before this partial
     * existed.
     */
    $extraSheets = array_filter((array) ($extraStyles ?? []));
@endphp

@foreach ($extraSheets as $sheet)
    <link rel="stylesheet" href="{{ \App\Support\Asset::url($sheet) }}">
@endforeach

{{-- The theme is applied before first paint so a dark-mode user never sees a
     white flash. --}}
<script>
    (function () {
        try {
            var stored = localStorage.getItem('meridian-theme');
            if (stored) { document.documentElement.setAttribute('data-theme', stored); }
        } catch (e) {}
    })();
</script>

@stack('styles')
