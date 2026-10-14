{{-- Shared customer fields. Expects $customer (null for create). --}}
<x-ui.input name="name" :label="__('Customer name')" :value="$customer?->name" :placeholder="__('e.g. Rahim Uddin')" required />
<div class="flex gap-3" style="flex-wrap: wrap;">
    <div style="flex: 1 1 160px;">
        <x-ui.input name="phone" :label="__('Phone')" :value="$customer?->phone" :placeholder="__('01XXXXXXXXX')" />
    </div>
    <div style="flex: 1 1 200px;">
        <x-ui.input name="email" type="email" :label="__('Email')" :value="$customer?->email" />
    </div>
</div>
<div class="flex gap-3" style="flex-wrap: wrap;">
    <div style="flex: 1 1 140px;">
        <x-ui.input name="code" :label="__('Code')" :value="$customer?->code" :placeholder="__('Optional')" />
    </div>
    <div style="flex: 1 1 150px;">
        <x-ui.select name="type" :label="__('Type')" :options="$customerTypes" :value="$customer?->type ?? 'regular'" required />
    </div>
</div>
<div class="flex gap-3" style="flex-wrap: wrap;">
    <div style="flex: 1 1 150px;">
        <x-ui.input name="credit_limit" type="number" :label="__('Credit limit')" :value="$customer ? (string) $customer->credit_limit : '0'" min="0" step="0.01" />
    </div>
    <div style="flex: 1 1 150px;">
        <x-ui.input name="opening_balance" type="number" :label="__('Opening balance')" :value="$customer ? (string) $customer->opening_balance : '0'" step="0.01" />
    </div>
</div>
<x-ui.input name="city" :label="__('City')" :value="$customer?->city" />
<x-ui.input name="tax_number" :label="__('Tax number')" :value="$customer?->tax_number" />
<div class="form-group">
    <label class="form-label" for="address-{{ $customer?->id ?? 'new' }}">{{ __('Address') }}</label>
    <textarea name="address" id="address-{{ $customer?->id ?? 'new' }}" class="form-control" rows="2">{{ $customer?->address }}</textarea>
</div>
<x-ui.checkbox name="is_active" :label="__('Active')" :checked="$customer ? $customer->is_active : true" value="1" />
