{{--
    Application shell.

    The page views use `<x-app-layout>` (the Laravel convention) with an
    optional `<x-slot:title>`. `layouts.app` is a plain document template, and
    Blade only resolves anonymous components under `resources/views/components`,
    so this thin wrapper bridges the two without duplicating the shell.
--}}
@php
    $documentTitle = $attributes->get('title') ?? ($title ?? null);
    $documentHeader = $attributes->get('header') ?? ($header ?? null);
    $documentExtraStyles = $attributes->get('extraStyles') ?? ($extraStyles ?? null);
@endphp

@include('layouts.app', [
    'slot' => $slot,
    'title' => $documentTitle,
    'header' => $documentHeader,
    'extraStyles' => $documentExtraStyles,
])
