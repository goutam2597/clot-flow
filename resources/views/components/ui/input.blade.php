@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'placeholder' => null,
    'hint' => null,
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'id' => null,
    'min' => null,
    'max' => null,
    'step' => null,
    'autofocus' => false,
    /** Extra classes for the wrapping .form-group, so a parent grid can
         control the cell without the class leaking onto the input itself. */
    'wrapperClass' => null,
])

@php
    $id = $id ?? $name;
    $hasError = $errors->has($name);
    $inputClasses = 'form-control' . ($hasError ? ' is-invalid' : '');
    $attrs = [
        'type' => $type,
        'name' => $name,
        'id' => $id,
        'class' => $inputClasses,
        'placeholder' => $placeholder,
        'value' => $type === 'password' ? null : (old($name, $value)),
    ];
    if ($required) { $attrs['required'] = 'required'; }
    if ($disabled) { $attrs['disabled'] = 'disabled'; }
    if ($readonly) { $attrs['readonly'] = 'readonly'; }
    if (!is_null($min)) { $attrs['min'] = $min; }
    if (!is_null($max)) { $attrs['max'] = $max; }
    if (!is_null($step)) { $attrs['step'] = $step; }
    if ($autofocus) { $attrs['autofocus'] = 'autofocus'; }
    if ($hasError) { $attrs['aria-invalid'] = 'true'; }
    if ($hasError) { $attrs['aria-describedby'] = $id . '-error'; }
@endphp

<div class="form-group {{ $wrapperClass }}">
    @if ($label)
        <label class="form-label" for="{{ $id }}">
            {{ $label }}@if ($required)<span class="required">*</span>@endif
        </label>
    @endif

    <input {{ $attributes->merge($attrs) }}>

    @if ($hint)
        <div class="form-hint">{{ $hint }}</div>
    @endif

    @error($name)
        <div class="form-error" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
</div>
