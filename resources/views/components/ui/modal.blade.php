@props(['id', 'title' => null, 'size' => 'md'])

@php
    $sizeClasses = [
        'sm' => 'modal-sm',
        'md' => '',
        'lg' => 'modal-lg',
        'xl' => 'modal-xl',
    ];
@endphp

<div class="modal-backdrop" id="{{ $id }}" data-modal="{{ $id }}">
    <div class="modal {{ $sizeClasses[$size] ?? '' }}" role="dialog" aria-modal="true" aria-label="{{ $title }}">
        <div class="modal-header">
            <h3 class="modal-title">{{ $title }}</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="{{ __('Close') }}">
                <x-ui.icon name="x" :size="18" />
            </button>
        </div>
        <div class="modal-body">
            {{ $slot }}
        </div>
        @if (isset($footer))
            <div class="modal-footer">
                {{ $footer }}
            </div>
        @endif
    </div>
</div>
