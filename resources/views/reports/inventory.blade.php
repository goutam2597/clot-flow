<x-app-layout>
    <x-slot:title>{{ __('Inventory health') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Inventory health')" :subtitle="__('Valuation and stock levels, computed from live stock balances.')" :back="route('reports.index')">
        <x-slot:actions>
            <x-ui.button variant="outline" icon="print" onclick="window.print()">{{ __('Print') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="money" :label="__('Stock valuation')" :value="\App\Support\Formatter::money($valuation)"
                            :hint="__('Quantity × average cost')" />
        </div>
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="package" :label="__('Stock lines')" :value="\App\Support\Formatter::number($lineCount)" />
        </div>
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="alert" :label="__('At or below reorder level')" :value="\App\Support\Formatter::number($lowCount)" />
        </div>
    </div>

    <div class="card mb-4">
        <form method="GET" action="{{ route('reports.inventory') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 250px;">
                        <x-ui.input name="search" :label="__('Search')" :value="$filters['search'] ?? ''"
                                    :placeholder="__('Product name or SKU')" />
                    </div>
                    <div style="flex: 1 1 200px;">
                        <x-ui.select name="warehouse_id" :label="__('Warehouse')" :options="$warehouses" :value="$filters['warehouse_id'] ?? ''"
                                     :placeholder="__('All warehouses')" />
                    </div>
                </div>
            </div>
            <div class="card-footer flex-center gap-3">
                <x-ui.button type="submit" icon="search">{{ __('Apply filters') }}</x-ui.button>
                <x-ui.button :href="route('reports.inventory')" variant="ghost">{{ __('Reset') }}</x-ui.button>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Product') }}</th>
                    <th>{{ __('Warehouse') }}</th>
                    <th class="num">{{ __('On hand') }}</th>
                    <th class="num">{{ __('Reorder level') }}</th>
                    <th class="num">{{ __('Average cost') }}</th>
                    <th class="num">{{ __('Value') }}</th>
                    <th>{{ __('State') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stocks as $stock)
                    @php
                        $isLow = bccomp((string) $stock->quantity, (string) $stock->product->reorder_level, 4) <= 0;
                        $isOut = bccomp((string) $stock->quantity, '0', 4) <= 0;
                    @endphp
                    <tr>
                        <td>
                            <div class="fw-medium">{{ $stock->product->name }}</div>
                            <div class="fs-xs text-muted font-mono">{{ $stock->product->sku }}</div>
                        </td>
                        <td class="fs-sm">{{ $stock->warehouse?->name ?? '—' }}</td>
                        <td class="num financial fw-semibold">{{ \App\Support\Formatter::number($stock->quantity, 2) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::number($stock->product->reorder_level, 0) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($stock->avg_cost) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money(
                            \App\Support\Money::mul((string) $stock->quantity, (string) $stock->avg_cost, 2)
                        ) }}</td>
                        <td>
                            @if ($isOut)
                                <x-ui.badge type="danger" dot>{{ __('Out of stock') }}</x-ui.badge>
                            @elseif ($isLow)
                                <x-ui.badge type="warning" dot>{{ __('Reorder') }}</x-ui.badge>
                            @else
                                <x-ui.badge type="success">{{ __('Healthy') }}</x-ui.badge>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-ui.empty-state icon="box" :title="__('No stock lines match these filters')"
                                            :text="__('Stock appears once goods are received into a warehouse.')" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {!! $stocks->links('partials.pagination') !!}
</x-app-layout>
