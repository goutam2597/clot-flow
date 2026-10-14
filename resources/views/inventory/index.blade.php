<x-app-layout>
    <x-slot:title>{{ __('Inventory') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Inventory')" :subtitle="__('Live stock levels in every warehouse, with adjustments and transfers.')">
        <x-slot:actions>
            @can('inventory.transfer')
                <x-ui.button :href="route('inventory.transfers')" variant="outline" icon="truck">{{ __('Stock Transfers') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex gap-3" style="flex-wrap: wrap;">
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="package" :label="__('Stock lines')" :value="\App\Support\Formatter::number($stats['lines'], 0)" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="money" :label="__('Stock value')" :value="\App\Support\Formatter::money($stats['stock_value'])" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="alert" :label="__('Low stock')" :value="\App\Support\Formatter::number($stats['low_stock'], 0)" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="x" :label="__('Out of stock')" :value="\App\Support\Formatter::number($stats['out_of_stock'], 0)" />
        </div>
    </div>

    <div class="card mb-4 mt-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Filters') }}</h2>
            @if ($filters['q'] !== '' || $filters['warehouse_id'] || $filters['category_id'] || $filters['stock'])
                <a href="{{ route('inventory.index') }}" class="fs-sm">{{ __('Clear all filters') }}</a>
            @endif
        </div>
        <form method="GET" action="{{ route('inventory.index') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 240px;">
                        <x-ui.input name="q" :label="__('Search')" :value="$filters['q']" :placeholder="__('Product name, SKU or barcode')" />
                    </div>
                    <div style="flex: 1 1 170px;">
                        <x-ui.select name="warehouse_id" :label="__('Warehouse')" :options="$warehouses" :value="$filters['warehouse_id']" :placeholder="__('All warehouses')" />
                    </div>
                    <div style="flex: 1 1 170px;">
                        <x-ui.select name="category_id" :label="__('Category')" :options="$categories" :value="$filters['category_id']" :placeholder="__('All categories')" />
                    </div>
                    <div style="flex: 1 1 130px;">
                        <x-ui.select name="stock" :label="__('Stock')" :options="['low' => __('Low stock'), 'out' => __('Out of stock')]" :value="$filters['stock']" :placeholder="__('Any stock')" />
                    </div>
                </div>
                <div class="flex gap-2 mt-3">
                    <x-ui.button type="submit" icon="search">{{ __('Apply') }}</x-ui.button>
                </div>
            </div>
        </form>
    </div>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('Product') }}</th>
                        <th>{{ __('Warehouse') }}</th>
                        <th class="num">{{ __('On hand') }}</th>
                        <th class="num">{{ __('Available') }}</th>
                        <th class="num">{{ __('Avg cost') }}</th>
                        <th>{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stocks as $stock)
                        @php
                            $available = bcsub((string) $stock->quantity, (string) $stock->reserved_quantity, 4);
                            $isLow = $stock->track_stock && (float) $stock->quantity > 0 && (float) $stock->quantity <= (float) $stock->reorder_level;
                            $isOut = $stock->track_stock && (float) $stock->quantity <= 0;
                        @endphp
                        <tr>
                            <td>
                                <div class="fw-medium">{{ $stock->product_name }}</div>
                                <div class="fs-xs text-muted font-mono">{{ $stock->product_sku }}</div>
                            </td>
                            <td>{{ $stock->warehouse_name }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::number($stock->quantity, 0) }}</td>
                            <td class="num financial fw-semibold">{{ \App\Support\Formatter::number($available, 0) }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::money($stock->avg_cost) }}</td>
                            <td>
                                @if (! $stock->track_stock)
                                    <x-ui.badge type="default">{{ __('Untracked') }}</x-ui.badge>
                                @elseif ($isOut)
                                    <x-ui.badge type="danger">{{ __('Out') }}</x-ui.badge>
                                @elseif ($isLow)
                                    <x-ui.badge type="warning">{{ __('Low') }}</x-ui.badge>
                                @else
                                    <x-ui.badge type="success">{{ __('OK') }}</x-ui.badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state icon="package" :title="__('No stock lines found')" :text="__('Stock appears here once products are received into a warehouse.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {!! $stocks->links('partials.pagination') !!}

    @can('inventory.adjust')
        <div class="card mt-4">
            <div class="card-header">
                <h2 class="card-title">{{ __('Manual adjustment') }}</h2>
            </div>
            <form method="POST" action="{{ route('inventory.adjust') }}" data-prevent-double>
                @csrf
                <div class="card-body">
                    <div class="flex gap-3" style="flex-wrap: wrap;">
                        <div style="flex: 1 1 180px;">
                            <x-ui.select name="warehouse_id" :label="__('Warehouse')" :options="$warehouses" required />
                        </div>
                        <div style="flex: 2 1 240px;">
                            <x-ui.select name="product_id" :label="__('Product')" :options="$products" required />
                        </div>
                        <div style="flex: 1 1 130px;">
                            <x-ui.input name="quantity" type="number" :label="__('Quantity')" step="0.0001" required :hint="__('Positive adds, negative removes.')" />
                        </div>
                    </div>
                    <x-ui.input name="memo" :label="__('Reason')" :placeholder="__('e.g. Damaged goods write-off')" />
                </div>
                <div class="card-footer flex gap-2" style="justify-content: flex-end;">
                    <x-ui.button type="submit" icon="check">{{ __('Post Adjustment') }}</x-ui.button>
                </div>
            </form>
        </div>
    @endcan

</x-app-layout>
