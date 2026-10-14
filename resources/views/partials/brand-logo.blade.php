@props(['variant' => 'auth'])

@php
    /*
     * The business identity, in one place.
     *
     * When a logo has been uploaded in settings it stands alone — no letter
     * mark, no wordmark, exactly as the owner asked. Without one the fallback
     * composition each surface already had is rendered, keyed by variant.
     *
     * SVG logos are inlined rather than <img>'d on purpose: an inline SVG
     * inherits the text colour around it, so a monochrome mark follows
     * light/dark mode automatically. A PNG cannot change its colours with
     * the theme — it shows exactly the pixels that were uploaded.
     */
    $logoType = \App\Support\BrandLogo::type();
    $logoSvg = $logoType === 'svg' ? \App\Support\BrandLogo::svg() : null;
    $logoPngUrl = $logoType === 'png' ? \App\Support\BrandLogo::url() : null;
    $brandName = \App\Support\Formatter::setting('legal_name', config('app.name', 'Meridian POS'));
@endphp

@if ($logoSvg)
    <span class="brand-logo brand-logo-{{ $variant }}" title="{{ $brandName }}">
        {!! $logoSvg !!}
    </span>
@elseif ($logoPngUrl)
    <span class="brand-logo brand-logo-{{ $variant }}">
        <img src="{{ $logoPngUrl }}" alt="{{ $brandName }}">
    </span>
@elseif ($variant === 'auth')
    <span class="auth-brand-mark">M</span>
    <span class="auth-brand-name">{{ $brandName }}</span>
@elseif ($variant === 'sidebar')
    <span class="sidebar-brand-mark">M</span>
    <span class="sidebar-brand-name">{{ config('app.name', 'Meridian POS') }}</span>
@endif
