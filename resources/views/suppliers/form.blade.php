{{-- Shared supplier fields. Expects $supplier (null for create). --}}
<x-ui.input name="name" :label="__('Supplier name')" :value="$supplier?->name" :placeholder="__('e.g. Dhaka Foods Ltd.')" required />
<div class="flex gap-3" style="flex-wrap: wrap;">
    <div style="flex: 1 1 160px;">
        <x-ui.input name="company" :label="__('Company')" :value="$supplier?->company" />
    </div>
    <div style="flex: 1 1 120px;">
        <x-ui.input name="code" :label="__('Code')" :value="$supplier?->code" :placeholder="__('Optional')" />
    </div>
</div>
<div class="flex gap-3" style="flex-wrap: wrap;">
    <div style="flex: 1 1 160px;">
        <x-ui.input name="phone" :label="__('Phone')" :value="$supplier?->phone" />
    </div>
    <div style="flex: 1 1 200px;">
        <x-ui.input name="email" type="email" :label="__('Email')" :value="$supplier?->email" />
    </div>
</div>
<x-ui.input name="city" :label="__('City')" :value="$supplier?->city" />
<x-ui.input name="tax_number" :label="__('Tax number')" :value="$supplier?->tax_number" />
<x-ui.input name="opening_balance" type="number" :label="__('Opening balance')" :value="$supplier ? (string) $supplier->opening_balance : '0'" step="0.01" />
<div class="form-group">
    <label class="form-label" for="address-{{ $supplier?->id ?? 'new' }}">{{ __('Address') }}</label>
    <textarea name="address" id="address-{{ $supplier?->id ?? 'new' }}" class="form-control" rows="2">{{ $supplier?->address }}</textarea>
</div>
<x-ui.checkbox name="is_active" :label="__('Active')" :checked="$supplier ? $supplier->is_active : true" value="1" />
