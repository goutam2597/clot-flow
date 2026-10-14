<x-app-layout>
    <x-slot:title>{{ __('Dashboard') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Dashboard')" :subtitle="__('Today at a glance across sales, stock and money owed.')">
        <x-slot:actions>
            @can('pos.access')
                <x-ui.button :href="route('pos.index')" icon="pos">{{ __('New Sale') }}</x-ui.button>
            @endcan
            @can('reports.view')
                <x-ui.button :href="route('reports.index')" variant="outline" icon="chart">{{ __('Reports') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Headline stats. `.stat-grid` keeps every card the same height. --}}
    <div class="stat-grid">
        <x-ui.stat-card icon="money" :label="__('Sales today')"
            :value="\App\Support\Formatter::money($stats['today_sales'])"
            :hint="__(':count invoices today', ['count' => \App\Support\Formatter::number($stats['today_count'], 0)])" />

        <x-ui.stat-card icon="sales" :label="__('Sales this month')"
            :value="\App\Support\Formatter::money($stats['month_sales'])"
            :hint="__(':count invoices', ['count' => \App\Support\Formatter::number($stats['month_count'], 0)])" />

        <x-ui.stat-card icon="chart" :label="__('Gross profit today')"
            :value="\App\Support\Formatter::money($stats['today_gross'])"
            :hint="__('Net of tax and discounts')" />

        <x-ui.stat-card icon="credit-card" :label="__('Customer dues')"
            :value="\App\Support\Formatter::money($stats['receivables'])"
            :hint="__('Outstanding on sales')" />

        <x-ui.stat-card icon="wallet" :label="__('Supplier dues')"
            :value="\App\Support\Formatter::money($stats['payables'])"
            :hint="__('Owed on purchases')" />
    </div>

    <div class="flex gap-4 mt-4" style="flex-wrap: wrap; align-items: flex-start;">
        {{-- Recent sales --}}
        <div class="card" style="flex: 2 1 480px;">
            <div class="card-header">
                <h2 class="card-title">{{ __('Recent sales') }}</h2>
                @can('sales.view')
                    <a href="{{ route('sales.index') }}" class="fs-sm">{{ __('View all') }}</a>
                @endcan
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Invoice') }}</th>
                            <th>{{ __('Customer') }}</th>
                            <th class="num">{{ __('Total') }}</th>
                            <th>{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentSales as $sale)
                            <tr>
                                <td>
                                    <a href="{{ route('sales.show', $sale) }}" class="fw-medium">{{ $sale->invoice_number }}</a>
                                    <div class="fs-xs text-muted">{{ \App\Support\Formatter::dateTime($sale->sold_at) }}</div>
                                </td>
                                <td>{{ $sale->customer?->name ?? __('Walk-in') }}</td>
                                <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($sale->grand_total) }}</td>
                                <td>@include('sales.partials.sale-status', ['status' => $sale->status])</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <x-ui.empty-state icon="receipt" :title="__('No sales yet today')"
                                        :text="__('Sales created at the point of sale will appear here.')" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div style="flex: 1 1 300px; display: flex; flex-direction: column; gap: 1rem;">
            {{-- Low stock --}}
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('Low stock') }}</h2>
                    @can('inventory.view')
                        <a href="{{ route('inventory.index') }}" class="fs-sm">{{ __('Inventory') }}</a>
                    @endcan
                </div>
                <div class="card-body" style="padding: 0;">
                    @forelse ($lowStock as $stock)
                        <div class="flex-between gap-2" style="padding: var(--space-2) var(--space-4); border-bottom: 1px solid var(--border-color);">
                            <div>
                                <div class="fw-medium fs-sm">{{ $stock->product?->name ?? '—' }}</div>
                                <div class="fs-xs text-muted">{{ $stock->product?->sku ?? '' }}</div>
                            </div>
                            <x-ui.badge type="warning">{{ \App\Support\Formatter::number($stock->quantity, 0) }}</x-ui.badge>
                        </div>
                    @empty
                        <x-ui.empty-state icon="package" :title="__('Stock levels healthy')" :text="__('Nothing is below reorder level.')" />
                    @endforelse
                </div>
            </div>

            {{-- Top products --}}
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('Top products this month') }}</h2>
                </div>
                <div class="card-body" style="padding: 0;">
                    @forelse ($topProducts as $row)
                        <div class="flex-between gap-2" style="padding: var(--space-2) var(--space-4); border-bottom: 1px solid var(--border-color);">
                            <div>
                                <div class="fw-medium fs-sm">{{ $row->name }}</div>
                                <div class="fs-xs text-muted">{{ __(':qty sold', ['qty' => \App\Support\Formatter::number($row->qty, 0)]) }}</div>
                            </div>
                            <div class="financial fw-semibold fs-sm">{{ \App\Support\Formatter::money($row->total) }}</div>
                        </div>
                    @empty
                        <x-ui.empty-state icon="box" :title="__('No product sales yet')" :text="__('Top sellers for this month will appear here.')" />
                    @endforelse
                </div>
            </div>

            {{-- Payment mix today --}}
            @if ($paymentBreakdown->isNotEmpty())
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('Collected today by method') }}</h2>
                    </div>
                    <div class="card-body" style="padding: 0;">
                        @foreach ($paymentBreakdown as $row)
                            <div class="flex-between gap-2" style="padding: var(--space-2) var(--space-4);">
                                <div class="fs-sm">{{ $row->paymentMethod?->name ?? __('Other') }}</div>
                                <div class="financial fw-semibold fs-sm">{{ \App\Support\Formatter::money($row->total) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- 7-day trend --}}
    <div class="card mt-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Sales — last 7 days') }}</h2>
            <span class="fs-sm text-muted">{{ __(':products products · :customers customers', ['products' => \App\Support\Formatter::number($productCount, 0), 'customers' => \App\Support\Formatter::number($customerCount, 0)]) }}</span>
        </div>
        <div class="card-body">
            @php $max = max(array_merge([1], array_column($trend, 'total'))); @endphp
            <div class="flex gap-2" style="align-items: flex-end; min-height: 120px;">
                @foreach ($trend as $day)
                    <div style="flex: 1 1 0; text-align: center;">
                        <div class="fs-xs financial text-muted">{{ \App\Support\Formatter::money($day['total'], false, 0) }}</div>
                        <div style="height: {{ max(4, round($day['total'] / $max * 80)) }}px; background: var(--color-primary-soft); border-radius: var(--radius-sm); margin: var(--space-1) 0;" title="{{ $day['date'] }} · {{ \App\Support\Formatter::money($day['total']) }}"></div>
                        <div class="fs-xs text-muted">{{ $day['label'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

</x-app-layout>
