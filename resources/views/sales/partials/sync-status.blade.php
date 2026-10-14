@php
    $map = [
        'synced' => 'success',
        'pending' => 'warning',
        'syncing' => 'info',
        'failed' => 'danger',
        'attention' => 'warning',
    ];

    $labels = [
        'synced' => __('Synced'),
        'pending' => __('Pending'),
        'syncing' => __('Syncing'),
        'failed' => __('Sync failed'),
        'attention' => __('Needs attention'),
    ];

    $sync = (string) ($sync ?? 'synced');
    $source = (string) ($source ?? 'online');
    $icon = $source === 'offline' ? 'cloud-off' : 'cloud';
@endphp

<div class="flex-center gap-2">
    <span class="flex-center gap-2 text-muted" title="{{ $source === 'offline' ? __('Captured offline') : __('Captured online') }}">
        <x-ui.icon :name="$icon" :size="14" />
        <span class="fs-xs">{{ $source === 'offline' ? __('Offline') : __('Online') }}</span>
    </span>
    <x-ui.badge :type="$map[$sync] ?? 'default'">{{ $labels[$sync] ?? __('—') }}</x-ui.badge>
</div>
