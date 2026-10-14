@props([
    'label' => null,
    'tabs' => [],
    'active' => null,
])

@php
    $activeTab = old('_tab', request('tab', $active));
    $tab = collect($tabs)->firstWhere('value', $activeTab) ?? collect($tabs)->first();
@endphp

<div class="settings-tabs">
    <div class="tabs settings-tabs-list" role="tablist" aria-label="{{ $label ?? __('Settings sections') }}">
        @foreach ($tabs as $option)
            <button type="button" role="tab" class="tab {{ $tab['value'] === $option['value'] ? 'active' : '' }}"
                    data-tab-target="{{ $option['value'] }}"
                    aria-selected="{{ $tab['value'] === $option['value'] ? 'true' : 'false' }}">
                {{ $option['label'] }}
            </button>
        @endforeach
    </div>

    {{ $slot }}
</div>
