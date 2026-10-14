@props([
    'title' => null,
    'text' => null,
    'icon' => null,
])

<div class="empty-state">
    @if ($icon)
        <div class="empty-state-icon"><x-ui.icon :name="$icon" :size="28" /></div>
    @endif
    @if ($title)<div class="empty-state-title">{{ $title }}</div>@endif
    @if ($text)<div class="empty-state-text">{{ $text }}</div>@endif
    @if (trim($slot))
        {{ $slot }}
    @endif
</div>

