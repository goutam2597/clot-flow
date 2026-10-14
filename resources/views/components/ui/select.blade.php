@props([
    'name',
    'label' => null,
    'options' => [],          // array: value => label  OR collection of models
    'optionValue' => 'id',
    'optionLabel' => 'name',
    'optionGroup' => null,    // attribute to group options under, when present
    'value' => null,
    'placeholder' => null,
    'hint' => null,
    'required' => false,
    'disabled' => false,
    'id' => null,
    'size' => null,           // sm | lg — matches the control height tokens
    'clearable' => null,       // null = clearable unless required
    /** Extra classes for the wrapping .form-group, so a parent grid can
         control the cell without the class leaking onto the select itself. */
    'wrapperClass' => null,
])

@php
    $id = $id ?? $name;
    $listId = $id . '-listbox';
    $hasError = $errors->has($name);

    // Normalize options to a flat list of {value, label, group, disabled}.
    // Doing it here rather than in JS means the no-JS path and the enhanced
    // path are rendering from the same list, and grouping survives either way.
    $items = [];
    foreach ($options as $k => $v) {
        if (is_object($v) && isset($v->{$optionValue})) {
            $items[] = [
                'value' => (string) $v->{$optionValue},
                'label' => (string) $v->{$optionLabel},
                'group' => $optionGroup ? ($v->{$optionGroup} ?? null) : null,
                'disabled' => (bool) ($v->disabled ?? false),
            ];
        } elseif (is_array($v) && array_key_exists($optionValue, $v)) {
            $items[] = [
                'value' => (string) $v[$optionValue],
                'label' => (string) $v[$optionLabel],
                'group' => $optionGroup ? ($v[$optionGroup] ?? null) : null,
                'disabled' => (bool) ($v['disabled'] ?? false),
            ];
        } else {
            $items[] = ['value' => (string) $k, 'label' => (string) $v, 'group' => null, 'disabled' => false];
        }
    }

    $selected = (string) old($name, $value);
    $selectedLabel = $placeholder;

    foreach ($items as $item) {
        if ($selected !== '' && $item['value'] === $selected) {
            $selectedLabel = $item['label'];
            break;
        }
    }

    $canClear = $clearable ?? ! $required;
    $searchId = $id . '-search';

    $nativeAttrs = [
        'name' => $name,
        'id' => $id,
        'class' => 'select-native' . ($hasError ? ' is-invalid' : ''),
        'tabindex' => '-1',
        'aria-hidden' => 'true',
        'data-select-native' => 'true',
    ];

    if ($required) { $nativeAttrs['data-required'] = 'true'; }
    if ($disabled) { $nativeAttrs['disabled'] = 'disabled'; }
    if ($hasError) { $nativeAttrs['data-invalid'] = 'true'; }
@endphp

<div class="form-group {{ $wrapperClass }}">
    @if ($label)
        <label class="form-label" for="{{ $id }}" id="{{ $id }}-label">{{ $label }}@if ($required)<span class="required">*</span>@endif</label>
    @endif

    {{-- The native <select> stays in the DOM and stays in the form: it is what
         POSTs the value and what renders when the script never arrives. CSS
         hides it once .is-enhanced lands on the wrapper. --}}
    <div class="select{{ $size ? ' select-' . $size : '' }}{{ $disabled ? ' is-disabled' : '' }}{{ $hasError ? ' is-invalid' : '' }}"
         data-select
         data-placeholder="{{ $placeholder ?? '' }}"
         data-search-placeholder="{{ __('Search…') }}"
         data-empty-text="{{ __('No matches') }}">
        <select {{ $attributes->merge($nativeAttrs) }}>
            @if ($placeholder)
                <option value="">{{ $placeholder }}</option>
            @endif
            @foreach ($items as $item)
                <option value="{{ $item['value'] }}"
                    @if ($item['group']) data-group="{{ $item['group'] }}" @endif
                    @disabled($item['disabled'])
                    {{ $selected === $item['value'] ? 'selected' : '' }}>{{ $item['label'] }}</option>
            @endforeach
        </select>

        {{-- One bordered shell holds the trigger and the clear button as flow
             siblings. The clear button is deliberately NOT inside the trigger:
             a button inside a button is invalid HTML, it exposes the control
             twice to a screen reader, and it breaks strict-mode automation. --}}
        <div class="select-shell">
            <button type="button" class="select-trigger"
                    aria-haspopup="listbox" aria-expanded="false"
                    aria-controls="{{ $listId }}"
                    @if ($label) aria-labelledby="{{ $id }}-label {{ $id }}-value" @else
                    aria-label="{{ $name }}" @endif
                    @disabled($disabled)>
                <span class="select-value{{ $selected === '' ? ' is-placeholder' : '' }}" id="{{ $id }}-value">{{ $selectedLabel }}</span>

                <svg class="select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>
        </div>

        {{-- Built by app.js from the native options. Present in the markup so
             the panel exists before the script runs and cannot flash unstyled. --}}
        <div class="select-panel" data-select-panel hidden>
            <div class="select-search-row">
                <div class="select-search-wrap">
                    <svg class="select-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="text" class="select-search" id="{{ $searchId }}"
                           data-select-search autocomplete="off" spellcheck="false"
                           role="combobox" aria-expanded="true" aria-controls="{{ $listId }}"
                           placeholder="{{ __('Search…') }}">
                </div>
            </div>

            <ul class="select-list" id="{{ $listId }}" data-select-list role="listbox" tabindex="-1"></ul>
        </div>
    </div>

    @if ($hint)
        <div class="form-hint">{{ $hint }}</div>
    @endif

    @error($name)
        <div class="form-error" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
</div>
