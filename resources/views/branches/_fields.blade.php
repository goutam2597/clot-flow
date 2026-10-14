{{--
    Branch fields, shared by the "add" form and each row's edit dialog.

    Expects:
      $branch    App\Models\Branch|null
--}}
@php
    $val = fn (string $key, $default = '') => old($key, $branch->{$key} ?? $default);
@endphp

<div class="flex gap-3" style="flex-wrap: wrap;">
    <div style="flex: 1 1 220px;">
        <x-ui.input name="name" :label="__('Branch name')" :value="$val('name')" required autofocus placeholder="e.g. Dhanmondi Branch" />
    </div>
    <div style="flex: 0 1 160px;">
        <x-ui.input name="code" :label="__('Code')" :value="$val('code')" required maxlength="32"
                    placeholder="DHL" hint="{{ __('Letters and numbers, stored uppercase.') }}" />
    </div>
    <div style="flex: 1 1 180px;">
        <x-ui.input name="phone" type="tel" :label="__('Phone')" :value="$val('phone')" />
    </div>
    <div style="flex: 1 1 220px;">
        <x-ui.input name="email" type="email" :label="__('Email')" :value="$val('email')" />
    </div>
    <div style="flex: 1 1 100%;">
        <x-ui.input name="address_line" :label="__('Address')" :value="$val('address_line')" />
    </div>
    <div style="flex: 1 1 160px;">
        <x-ui.input name="city" :label="__('City')" :value="$val('city')" />
    </div>
    <div style="flex: 1 1 160px;">
        <x-ui.input name="country" :label="__('Country')" :value="$val('country')" />
    </div>
</div>

<div class="form-group mb-0">
    <input type="hidden" name="is_active" value="0">
    {{-- The switch component, not a hand-rolled track. This was a label wrapping
         a field-variant checkbox (nested labels) plus a separate track span the
         buried input could never reach as a sibling, so `input:checked + track`
         never fired: the value toggled invisibly and the knob never moved. --}}
    <x-ui.checkbox variant="switch" name="is_active" value="1"
                   :label="__('Active')" :hint="__('Active — branch is selectable for new records')"
                   :checked="$branch->is_active ?? true" />
</div>
