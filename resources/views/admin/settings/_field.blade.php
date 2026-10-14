@php
    $current = old($field['key'], $field['value']);
    $current = $current === null || $current === '' ? $field['default'] : $current;
    $id = 'setting-' . $field['key'];
@endphp

@if ($field['type'] === 'boolean')
    {{-- The hidden "0" is what makes an unticked box post 0 rather than
         nothing: without it an unchecked setting is indistinguishable from an
         absent one and the default silently wins. --}}
    <input type="hidden" name="{{ $field['key'] }}" value="0">

    <x-ui.checkbox variant="switch"
                   id="{{ $id }}"
                   name="{{ $field['key'] }}"
                   value="1"
                   :label="$field['label']"
                   :hint="$field['hint']"
                   :checked="(bool) $current"
                   :disabled="$field['required'] && $current === '' && ! old($field['key'])" />

    @error($field['key'])
        <div class="form-error">{{ $message }}</div>
    @enderror
@elseif ($field['type'] === 'select')
    <x-ui.select :name="$field['key']" :id="$id" :label="$field['label']" :options="$field['options']"
                 :value="$current" :hint="$field['hint']" :required="$field['required']"
                 :placeholder="$field['required'] ? null : __('Not set')" />
@elseif ($field['type'] === 'textarea')
    <div class="form-group">
        <label class="form-label" for="{{ $id }}">
            {{ $field['label'] }}@if ($field['required'])<span class="required">*</span>@endif
        </label>
        <textarea class="form-control{{ $errors->has($field['key']) ? ' is-invalid' : '' }}"
                  id="{{ $id }}" name="{{ $field['key'] }}" rows="3"
                  @if ($field['required']) required @endif>{{ old($field['key'], $field['value']) }}</textarea>
        @if ($field['hint'])<div class="form-hint">{{ $field['hint'] }}</div>@endif
        @error($field['key'])<div class="form-error">{{ $message }}</div>@enderror
    </div>
@else
    <x-ui.input :name="$field['key']" :id="$id"
                :type="$field['type'] === 'integer' ? 'number' : $field['type']"
                :label="$field['label']" :value="$current" :hint="$field['hint']"
                :required="$field['required']"
                :min="$field['min']" :max="$field['max']" />
@endif
