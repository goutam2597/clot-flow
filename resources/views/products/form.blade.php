{{--
    Product form fields, shared by the create and edit modals.
    Expects: $product (null for create), $useOld (bool), plus the
    $categories / $brands / $units / $taxRates / $productTypes collections.
--}}
@php
    $product = $product ?? null;
    $useOld = $useOld ?? false;
    $val = $useOld
        ? fn ($field, $default = null) => old($field, $default)
        : fn ($field, $default = null) => $default;
@endphp

<x-ui.input
    name="name"
    :label="__('Product name')"
    :value="$val('name', $product?->name)"
    :placeholder="__('e.g. Wireless Mouse')"
    required
    autofocus
/>

<div class="flex gap-3" style="flex-wrap: wrap;">
    <div style="flex: 1 1 200px;">
        <x-ui.input
            name="sku"
            :label="__('SKU')"
            :value="$val('sku', $product?->sku)"
            :placeholder="__('PRD-0001')"
            :hint="__('Must be unique across the catalog.')"
            required
        />
    </div>
    <div style="flex: 1 1 200px;">
        <x-ui.input
            name="barcode"
            :label="__('Barcode')"
            :value="$val('barcode', $product?->barcode)"
            :placeholder="__('Optional')"
        />
    </div>
</div>

<x-ui.select
    name="category_id"
    :label="__('Category')"
    :options="$categories"
    :value="$val('category_id', $product?->category_id)"
    :placeholder="__('Uncategorised')"
/>

<div class="flex gap-3" style="flex-wrap: wrap;">
    <div style="flex: 1 1 160px;">
        <x-ui.select
            name="brand_id"
            :label="__('Brand')"
            :options="$brands"
            :value="$val('brand_id', $product?->brand_id)"
            :placeholder="__('None')"
        />
    </div>
    <div style="flex: 1 1 140px;">
        <x-ui.select
            name="unit_id"
            :label="__('Unit')"
            :options="$units"
            :value="$val('unit_id', $product?->unit_id)"
            :placeholder="__('None')"
        />
    </div>
    <div style="flex: 1 1 160px;">
        <x-ui.select
            name="tax_rate_id"
            :label="__('Tax rate')"
            :options="$taxRates"
            option-label="name"
            :value="$val('tax_rate_id', $product?->tax_rate_id)"
            :placeholder="__('No tax')"
        />
    </div>
</div>

<div class="flex gap-3" style="flex-wrap: wrap;">
    <div style="flex: 1 1 160px;">
        <x-ui.input
            name="cost_price"
            type="number"
            :label="__('Cost price')"
            :value="$val('cost_price', $product ? (string) $product->cost_price : '0.00')"
            min="0"
            step="0.01"
            required
        />
    </div>
    <div style="flex: 1 1 160px;">
        <x-ui.input
            name="selling_price"
            type="number"
            :label="__('Selling price')"
            :value="$val('selling_price', $product ? (string) $product->selling_price : '0.00')"
            min="0"
            step="0.01"
            required
        />
    </div>
    <div style="flex: 1 1 140px;">
        <x-ui.input
            name="reorder_level"
            type="number"
            :label="__('Reorder level')"
            :value="$val('reorder_level', $product ? (string) $product->reorder_level : '0')"
            min="0"
            step="1"
            :hint="__('Warn when stock falls to this level.')"
        />
    </div>
</div>

<div class="flex gap-3" style="flex-wrap: wrap;">
    <div style="flex: 1 1 160px;">
        <x-ui.select
            name="type"
            :label="__('Product type')"
            :options="$productTypes"
            :value="$val('type', $product?->type ?? 'simple')"
        />
    </div>
    <div style="flex: 1 1 160px;">
        <x-ui.select
            name="status"
            :label="__('Status')"
            :options="['active' => __('Active'), 'inactive' => __('Inactive')]"
            :value="$val('status', $product?->status ?? 'active')"
            required
        />
    </div>
</div>

{{--
    Images come before the long-form fields: a photo is what someone reaches
    the product screen to check, and burying it under the description means it
    gets missed and the product ships with a blank tile on the till.
--}}
<x-ui.image-field
    name="image"
    :value="$product?->image_url"
    :hint="__('JPG, PNG, WebP or GIF, up to 4 MB. A square photo reads best on the terminal.')"
/>

<div class="form-group">
    <label class="form-label" for="description-{{ $product?->id ?? 'new' }}">{{ __('Description') }}</label>
    <textarea
        name="description"
        id="description-{{ $product?->id ?? 'new' }}"
        class="form-control"
        rows="3"
        placeholder="{{ __('Optional notes shown on receipts and invoices') }}"
    >{{ $val('description', $product?->description) }}</textarea>
    @error('description')
        <div class="form-error">{{ $message }}</div>
    @enderror
</div>

<div class="form-group">
    <x-ui.checkbox
        name="track_stock"
        :label="__('Track stock for this product')"
        :checked="$val('track_stock', $product ? (bool) $product->track_stock : true)"
        value="1"
    />
    <x-ui.checkbox
        name="allow_negative_stock"
        :label="__('Allow negative stock')"
        :checked="$val('allow_negative_stock', $product ? (bool) $product->allow_negative_stock : false)"
        value="1"
        :hint="__('Useful for services or made-to-order items.')"
    />
</div>
