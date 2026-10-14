<x-app-layout>
    <x-slot:title>{{ __('New purchase') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @php
        $statusOptions = collect($statuses)->mapWithKeys(fn ($s) => [$s => __(ucfirst($s))])->all();
        $taxOptions = $taxRates->pluck('name', 'id');

        // Everything the line editor needs, delivered as one JSON payload.
        $purchaseBootstrap = [
            'products' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'cost' => (string) $p->cost_price,
                'tax_rate_id' => $p->tax_rate_id,
            ])->values(),
            'taxRates' => $taxRates->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'rate' => (string) $t->rate,
            ])->values(),
            'currency' => [
                'symbol' => \App\Support\Formatter::currency()->symbol,
                'position' => \App\Support\Formatter::currency()->symbol_position,
                'decimals' => (int) \App\Support\Formatter::currency()->decimal_places,
                'thousand' => \App\Support\Formatter::currency()->thousand_separator,
                'decimal' => \App\Support\Formatter::currency()->decimal_separator,
            ],
            'labels' => [
                'chooseProduct' => __('Choose a product'),
                'noTax' => __('No tax'),
                'unitCost' => __('Unit cost'),
            ],
            'old' => [
                'items' => old('items', []),
                'discount_total' => old('discount_total', '0'),
                'shipping_total' => old('shipping_total', '0'),
            ],
        ];
    @endphp

    @include('partials.flash')

    <x-ui.page-header :title="__('New purchase')" :subtitle="__('Totals are recalculated on the server. Saving as “Received” increases stock and posts accounts payable immediately.')"
                     :back="route('purchases.index')" />

    <form method="POST" action="{{ route('purchases.store') }}" id="purchase-form" data-prevent-double>
        @csrf

        <div class="flex gap-4 mb-4" style="flex-wrap: wrap; align-items: flex-start;">
            {{-- Header --}}
            <div style="flex: 2 1 460px;">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">{{ __('Document') }}</h2></div>
                    <div class="card-body">
                        <div class="flex gap-3" style="flex-wrap: wrap;">
                            <div style="flex: 1 1 240px;">
                                <x-ui.select name="supplier_id" :label="__('Supplier')" required :options="$suppliers" :value="old('supplier_id')"
                                             :placeholder="__('Choose a supplier')" />
                            </div>
                            <div style="flex: 1 1 220px;">
                                <x-ui.select name="warehouse_id" :label="__('Receive into')" required :options="$warehouses" :value="old('warehouse_id')"
                                             :placeholder="__('Choose a warehouse')"
                                             :hint="__('Stock is added to this warehouse.')" />
                            </div>
                            <div style="flex: 1 1 200px;">
                                <x-ui.select name="branch_id" :label="__('Branch')" :options="$branches"
                                             :value="old('branch_id', auth()->user()->branch_id)" :placeholder="__('Your branch')" />
                            </div>
                            <div style="flex: 1 1 180px;">
                                <x-ui.input name="purchased_at" type="date" :label="__('Purchase date')" required
                                            :value="old('purchased_at', now()->toDateString())" />
                            </div>
                            <div style="flex: 1 1 190px;">
                                <x-ui.select name="status" :label="__('Save as')" required :options="$statusOptions"
                                             :value="old('status', 'received')"
                                             :hint="__('“Received” posts stock and the payable now.')" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Money --}}
            <div style="flex: 1 1 320px;">
                <div class="card">
                    <div class="card-header"><h2 class="card-title">{{ __('Amounts') }}</h2></div>
                    <div class="card-body">
                        <x-ui.input name="discount_total" type="number" step="0.01" min="0" :label="__('Order discount')"
                                    :value="old('discount_total', '0.00')" />
                        <x-ui.input name="shipping_total" type="number" step="0.01" min="0" :label="__('Shipping')"
                                    :value="old('shipping_total', '0.00')" />
                        <x-ui.input name="paid_total" type="number" step="0.01" min="0" :label="__('Amount paid now')"
                                    :value="old('paid_total', '0.00')"
                                    :hint="__('Anything left unpaid becomes an outstanding payable.')" />
                        <div class="form-group mb-0">
                            <label class="form-label" for="purchase-notes">{{ __('Notes') }}</label>
                            <textarea class="form-control" id="purchase-notes" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Lines --}}
        <div class="card mb-4">
            <div class="card-header flex-between">
                <h2 class="card-title">{{ __('Items') }}</h2>
                <x-ui.button type="button" variant="outline" size="sm" icon="plus" id="purchase-add-line">
                    {{ __('Add line') }}
                </x-ui.button>
            </div>

            <div class="table-wrap">
                <table class="data-table" id="purchase-lines">
                    <thead>
                        <tr>
                            <th>{{ __('Product') }}</th>
                            <th class="num" style="width: 120px;">{{ __('Quantity') }}</th>
                            <th class="num" style="width: 140px;">{{ __('Unit cost') }}</th>
                            <th class="num" style="width: 140px;">{{ __('Discount') }}</th>
                            <th style="width: 190px;">{{ __('Tax') }}</th>
                            <th class="num">{{ __('Line total') }}</th>
                            <th class="cell-actions" style="width: 48px;"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="card-footer">
                <div class="flex" style="justify-content: flex-end; gap: var(--space-8);">
                    <div style="min-width: 220px;">
                        <div class="summary-row">
                            <span class="text-secondary">{{ __('Subtotal') }}</span>
                            <span class="financial fw-semibold" id="purchase-subtotal">0.00</span>
                        </div>
                        <div class="summary-row">
                            <span class="text-secondary">{{ __('Tax') }}</span>
                            <span class="financial" id="purchase-tax">0.00</span>
                        </div>
                        <div class="summary-row is-grand">
                            <span class="fw-semibold">{{ __('Grand total') }}</span>
                            <span class="financial fw-semibold" id="purchase-total">0.00</span>
                        </div>
                    </div>
                </div>
                <p class="fs-xs text-muted mt-3 mb-0">
                    {{ __('Figures shown here are a preview. The server recalculates every line before saving.') }}
                </p>
            </div>
        </div>

        <div class="form-actions">
            <x-ui.button type="submit" icon="check">{{ __('Save purchase') }}</x-ui.button>
            <x-ui.button :href="route('purchases.index')" variant="outline">{{ __('Cancel') }}</x-ui.button>
        </div>
    </form>

    @push('scripts')
        <script id="purchase-bootstrap" type="application/json">@json($purchaseBootstrap)</script>
        <script src="{{ asset('js/purchase.js') }}"></script>
    @endpush
</x-app-layout>
