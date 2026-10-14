{{--
    Language fields, shared by the "add" form and each row's edit dialog.

    Expects:
      $language    App\Models\Language|null
      $directions  key => label
--}}
@php
    $isEdit = $language !== null;
    $val = fn (string $key, $default = '') => old($key, $language->{$key} ?? $default);
@endphp

<div class="flex gap-3" style="flex-wrap: wrap;">
    <div style="flex: 1 1 200px;">
        <x-ui.input name="name" :label="__('Name')" :value="$val('name')" required placeholder="e.g. Arabic" />
    </div>
    <div style="flex: 1 1 200px;">
        <x-ui.input name="native_name" :label="__('Native name')" :value="$val('native_name')"
                    :hint="__('Shown to users in their own script, e.g. বাংলা.')" />
    </div>
    <div style="flex: 1 1 140px;">
        <x-ui.input name="code" :label="__('Code')" :value="$val('code')" required placeholder="bn" :minlength="2" :maxlength="10"
                    :hint="__('Short language code, e.g. en, bn, ar.')" />
    </div>
    <div style="flex: 1 1 180px;">
        <x-ui.input name="locale" :label="__('Locale')" :value="$val('locale')" placeholder="bn_BD"
                    :hint="__('Used for dates and numbers.')" />
    </div>
    <div style="flex: 1 1 180px;">
        <x-ui.select name="direction" :label="__('Direction')" required :options="$directions"
                     :value="$val('direction', 'ltr')" />
    </div>
    <div style="flex: 1 1 140px;">
        <x-ui.input name="sort_order" type="number" :label="__('Order')" :value="$val('sort_order', '0')" min="0" max="65535" />
    </div>
</div>

{{-- Hidden companions guarantee an explicit 0 when a checkbox is cleared:
     without them, unticking posts nothing and the stored value survives. --}}
<div class="flex gap-4 mt-2" style="flex-wrap: wrap;">
    <div>
        <input type="hidden" name="is_active" value="0">
        <x-ui.checkbox name="is_active" value="1"
                       :label="__('Active')"
                       :checked="old('is_active', $language->is_active ?? true)"
                       :hint="__('Selectable in the language switcher')" />
    </div>

    <div>
        <input type="hidden" name="is_default" value="0">
        <x-ui.checkbox name="is_default" value="1"
                       :label="__('Default language')"
                       :checked="old('is_default', $language->is_default ?? false)"
                       :hint="__('Default language for new sessions')" />
    </div>
</div>
