{{--
    Till shell wrapper.

    Same bridge as x-layouts.app: `layouts.till` is a document layout that
    prints `{{ $slot }}`, and Blade only resolves anonymous components inside
    resources/views/components. This exposes it to page views as
    `<x-layouts.till>` without duplicating the document.

    The till layout deliberately has no `header` slot: there is no topbar to
    receive one. Passing a header to it would be silently discarded, which is
    worse than not offering it.
--}}
@php
    $shellTitle = $attributes->get('title', $title ?? null);
@endphp

@include('layouts.till', [
    'slot' => $slot,
    'title' => $shellTitle,
])
