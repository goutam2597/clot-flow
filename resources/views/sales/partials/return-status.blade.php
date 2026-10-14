@php
    $map = [
        'completed' => 'success',
        'voided' => 'danger',
    ];

    $labels = [
        'completed' => __('Completed'),
        'voided' => __('Voided'),
    ];

    $status = (string) ($status ?? '');
@endphp

<x-ui.badge :type="$map[$status] ?? 'default'" :dot="true">{{ $labels[$status] ?? __('—') }}</x-ui.badge>
