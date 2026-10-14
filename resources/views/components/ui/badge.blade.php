@props([
    'type' => 'default',   // default, primary, success, warning, danger, info
    'dot' => false,
])

@php
    $classes = 'badge';
    if ($type !== 'default') { $classes .= ' badge-' . $type; }
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    @if ($dot)<span class="badge-dot"></span>@endif
    {{ $slot }}
</span>
