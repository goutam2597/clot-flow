{{--
    Application shell wrapper.

    `layouts.app` is written as a component layout (it prints `{{ $slot }}`), while
    Blade only resolves anonymous components inside `resources/views/components`.
    This thin wrapper exposes that shared layout to page views as `<x-layouts.app>`
    without duplicating the document shell, and forwards the optional `title` /
    `header` slots — or the matching attributes — to the document title and the
    topbar page title.
--}}
@php
    $shellTitle = $attributes->get('title', $title ?? null);
    $shellHeader = $attributes->get('header', $header ?? null);
@endphp

@include('layouts.app', [
    'slot' => $slot,
    'title' => $shellTitle,
    'header' => $shellHeader,
])
