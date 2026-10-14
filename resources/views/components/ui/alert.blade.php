@props([
    'type' => 'success',   // success, danger, warning, info
    'title' => null,
    'dismissible' => false,
])

@php
    $icons = [
        'success' => 'check-circle',
        'danger' => 'alert',
        'warning' => 'alert',
        'info' => 'info',
    ];
@endphp

<div class="alert alert-{{ $type }}" role="alert">
    <x-ui.icon :name="$icons[$type] ?? 'info'" :size="18" />
    <div class="alert-body">
        @if ($title)<div class="alert-title">{{ $title }}</div>@endif
        <div class="alert-content">{{ $slot }}</div>
    </div>
    @if ($dismissible)
        <button type="button" class="alert-close" data-alert-dismiss aria-label="{{ __('Dismiss') }}">
            <x-ui.icon name="x" :size="16" />
        </button>
    @endif
</div>
