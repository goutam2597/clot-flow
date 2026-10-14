@props([
    'label',
    'value',
    'icon' => null,
    'trend' => null,       // up | down
    'trendValue' => null,
    'hint' => null,
])

<div class="stat-card">
    @if ($icon)
    <div class="stat-card-icon"><x-ui.icon :name="$icon" :size="20" /></div>
    @endif
    <div class="stat-card-label">{{ $label }}</div>
    <div class="stat-card-value">{{ $value }}</div>
    @if ($hint || $trendValue)
    <div class="stat-card-meta">
        @if ($trend && $trendValue)
            <span class="{{ $trend === 'up' ? 'text-success' : 'text-danger' }} flex-center gap-2 fs-xs">
                <x-ui.icon :name="$trend === 'up' ? 'trending-up' : 'trending-down'" :size="14" />
                {{ $trendValue }}
            </span>
        @endif
        @if ($hint)<span class="fs-xs">{{ $hint }}</span>@endif
    </div>
    @endif
</div>

