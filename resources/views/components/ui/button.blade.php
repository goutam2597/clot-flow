@props([
    'variant' => 'primary',   // primary, secondary, outline, ghost, danger, success, danger-ghost
    'size' => 'md',           // sm, md, lg
    'type' => 'button',
    'icon' => null,
    'iconRight' => null,
    'href' => null,
    'loading' => false,
    'block' => false,
])

@php
    $classes = 'btn btn-' . $variant;
    if ($size !== 'md') { $classes .= ' btn-' . $size; }
    if ($block) { $classes .= ' btn-block'; }
    if ($loading) { $classes .= ' is-loading'; }
    $attributes = $attributes->merge(['class' => $classes]);
    if ($type !== 'link' && !$href) { $attributes = $attributes->merge(['type' => $type]); }
    if ($loading) { $attributes = $attributes->merge(['disabled' => 'disabled', 'aria-busy' => 'true']); }
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes }}>
        <span class="spinner"></span>
        @if ($icon)<x-ui.icon :name="$icon" :size="$size === 'sm' ? 15 : 16" />@endif
        @if ($slot->isNotEmpty())<span class="btn-label">{{ $slot }}</span>@endif
        @if ($iconRight)<x-ui.icon :name="$iconRight" :size="$size === 'sm' ? 15 : 16" />@endif
    </a>
@else
    <button {{ $attributes }}>
        <span class="spinner"></span>
        @if ($icon)<x-ui.icon :name="$icon" :size="$size === 'sm' ? 15 : 16" />@endif
        @if ($slot->isNotEmpty())<span class="btn-label">{{ $slot }}</span>@endif
        @if ($iconRight)<x-ui.icon :name="$iconRight" :size="$size === 'sm' ? 15 : 16" />@endif
    </button>
@endif
