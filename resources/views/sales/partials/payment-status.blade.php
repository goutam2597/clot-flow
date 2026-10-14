@php
    $map = [
        'paid' => 'success',
        'partial' => 'warning',
        'due' => 'danger',
    ];

    $labels = [
        'paid' => __('Paid'),
        'partial' => __('Partially paid'),
        'due' => __('Unpaid'),
    ];

    $status = (string) ($status ?? '');
@endphp

<x-ui.badge :type="$map[$status] ?? 'default'">{{ $labels[$status] ?? __('—') }}</x-ui.badge>
