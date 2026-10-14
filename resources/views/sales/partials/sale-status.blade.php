@php
    $map = [
        'completed' => 'success',
        'held' => 'warning',
        'voided' => 'danger',
        'returned' => 'info',
    ];

    $labels = [
        'completed' => __('Completed'),
        'held' => __('Held'),
        'voided' => __('Voided'),
        'returned' => __('Returned'),
    ];

    $status = (string) ($status ?? '');
@endphp

<x-ui.badge :type="$map[$status] ?? 'default'" :dot="true">{{ $labels[$status] ?? __('—') }}</x-ui.badge>
