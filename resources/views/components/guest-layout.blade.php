{{--
    Centred auth shell used by sign-in, password reset and similar screens.
--}}
@php
    $documentTitle = $attributes->get('title') ?? ($title ?? null);
@endphp

@include('layouts.guest', [
    'slot' => $slot,
    'title' => $documentTitle,
])
