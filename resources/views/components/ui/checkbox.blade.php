@props([
    'name' => null,
    'value' => 1,
    'label' => null,
    'hint' => null,
    'checked' => false,
    'indeterminate' => false,
    'id' => null,
    'disabled' => false,
    'required' => false,

    /**
     * row     - the box and nothing else, for a table cell. No visible label,
     *           so :label becomes the aria-label.
     * switch  - the sliding variant, for a setting that reads as on/off rather
     *           than as a tick.
     * field   - box plus label, for a form.
     */
    'variant' => 'field',

    /** Extra classes for the wrapping element. */
    'wrapperClass' => null,
])

@php
    // A generated id keeps the label bound to the input. Without one the label
    // is decoration and clicking the text does nothing.
    $id = $id ?? 'check-'.substr(md5(($name ?? 'box').($value ?? '')), 0, 10);
    $isChecked = (bool) old($name, $checked);
    $isDisabled = (bool) $disabled;

    $box = [
        'id' => $id,
        'name' => $name,
        'value' => $value,
        'checked' => $isChecked,
        'indeterminate' => (bool) $indeterminate,
        'disabled' => $isDisabled,
        'required' => (bool) $required,
        'label' => $variant === 'row' ? $label : null,
        'attributes' => $attributes,
    ];
@endphp

@if ($variant === 'row')
    @include('partials.checkbox-box', $box)
@elseif ($variant === 'switch')
    <label class="checkbox checkbox-switch {{ $wrapperClass }}" @if ($isDisabled) is-disabled @endif>
        {{-- The switch draws its own track, so it needs the input laid over it
             rather than beside it. The tick glyphs are hidden: a slider that
             also shows a tick says "on" twice and reads as a mistake. --}}
        <input type="checkbox" class="checkbox-input" id="{{ $id }}"
               @if ($name) name="{{ $name }}" @endif
               value="{{ $value }}"
               @checked($isChecked)
               @disabled($isDisabled)
               @if ($indeterminate) data-indeterminate="true" @endif
               role="switch"
               {{ $attributes }}>

        <span class="checkbox-track" aria-hidden="true"><span class="checkbox-knob"></span></span>

        @if ($label)
            <span class="checkbox-text">
                <span class="checkbox-label">{{ $label }}</span>
                @if ($hint)<span class="checkbox-hint">{{ $hint }}</span>@endif
            </span>
        @endif
    </label>
@else
    <div class="checkbox-field {{ $wrapperClass }}">
        <label class="checkbox {{ $isDisabled ? 'is-disabled' : '' }}" for="{{ $id }}">
            @include('partials.checkbox-box', $box + ['label' => null])

            <span class="checkbox-label">{{ $label ?? '' }}</span>
        </label>

        @if ($hint)
            <div class="checkbox-hint">{{ $hint }}</div>
        @endif
    </div>
@endif

@error($name)
    <div class="form-error" id="{{ $id }}-error">{{ $message }}</div>
@enderror
