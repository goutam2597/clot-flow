@props(['title', 'subtitle' => null, 'href' => null])

@php
    $body = $slot;
@endphp

@if ($href)
    <a href="{{ $href }}" class="settings-link-card">
        <span class="min-w-0">
            <span class="block fs-sm fw-semibold">{{ $title }}</span>
            <span class="block fs-sm text-muted mt-1">{{ $subtitle }}</span>
        </span>
        <x-ui.icon name="chevron-right" :size="16" class="mt-1 flex-shrink-0" />
    </a>
@else
    <div class="settings-panel">
        <div class="settings-panel-head">
            <div class="min-w-0">
                <h2 class="fs-md fw-semibold">{{ $title }}</h2>
                @if ($subtitle)<p class="fs-sm text-muted mt-1">{{ $subtitle }}</p>@endif
            </div>
            @if (isset($actions))<div class="settings-panel-actions">{{ $actions }}</div>@endif
        </div>
        <div class="settings-panel-body">
            {{ $body }}
        </div>
    </div>
@endif
