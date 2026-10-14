<x-app-layout>
    <x-slot:title>{{ __('Sales') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @php
        $statusOptions = collect($statuses)->mapWithKeys(fn ($value) => [$value => __(ucfirst($value))])->all();
        $paymentOptions = collect($paymentStatuses)->mapWithKeys(fn ($value) => [$value => __(ucfirst($value))])->all();
        $sourceOptions = collect($sources)->mapWithKeys(fn ($value) => [$value => $value === 'offline' ? __('Offline') : __('Online')])->all();
        $syncOptions = collect($syncStatuses)->mapWithKeys(fn ($value) => [$value => __(ucfirst(str_replace('_', ' ', $value)))])->all();
        $perPageOptions = collect($perPageOptions)->mapWithKeys(fn ($value) => [$value => (string) $value])->all();
        $hasFilters = $filters['search'] !== '' || $filters['status'] !== '' || $filters['payment_status'] !== ''
            || $filters['source'] !== '' || $filters['sync_status'] !== '' || $filters['from_raw'] !== '' || $filters['to_raw'] !== '';
    @endphp

    @include('partials.flash')

    <x-ui.page-header :title="__('Sales')" :subtitle="__('Every completed, held and voided sale with its payment and sync state.')">
        <x-slot:actions>
            @can('pos.access')
                <x-ui.button :href="route('pos.index')" icon="pos">{{ __('New Sale') }}</x-ui.button>
            @endcan
            <x-ui.button :href="route('sales.returns')" variant="outline" icon="refresh">{{ __('Returns & Refunds') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Register summary for the current filter --}}
    <div class="flex gap-3" style="flex-wrap: wrap;">
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="sales" :label="__('Sales value')" :value="\App\Support\Formatter::money($summary['grand_total'])" :hint="__(':count invoices', ['count' => \App\Support\Formatter::number($summary['count'])])" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="money" :label="__('Collected')" :value="\App\Support\Formatter::money($summary['paid_total'])" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="credit-card" :label="__('Outstanding dues')" :value="\App\Support\Formatter::money($summary['due_total'])" />
        </div>
        <div style="flex: 1 1 190px;">
            <x-ui.stat-card icon="x" :label="__('Voided sales')" :value="\App\Support\Formatter::number($summary['voided'])" />
        </div>
    </div>

    {{-- Filters --}}
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Filters') }}</h2>
            @if ($hasFilters)
                <a href="{{ route('sales.index') }}" class="fs-sm">{{ __('Clear all filters') }}</a>
            @endif
        </div>

        <form method="GET" action="{{ route('sales.index') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 260px;">
                        <x-ui.input name="search" :label="__('Search')" :value="$filters['search']" :placeholder="__('Invoice number, customer name or phone')" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.input name="from" type="date" :label="__('Sold from')" :value="$filters['from_raw']" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.input name="to" type="date" :label="__('Sold until')" :value="$filters['to_raw']" />
                    </div>
                    <div style="flex: 1 1 150px;">
                        <x-ui.select name="status" :label="__('Status')" :options="$statusOptions" :value="$filters['status']" :placeholder="__('All statuses')" />
                    </div>
                    <div style="flex: 1 1 150px;">
                        <x-ui.select name="payment_status" :label="__('Payment')" :options="$paymentOptions" :value="$filters['payment_status']" :placeholder="__('Any payment state')" />
                    </div>
                    <div style="flex: 1 1 150px;">
                        <x-ui.select name="source" :label="__('Source')" :options="$sourceOptions" :value="$filters['source']" :placeholder="__('Online & offline')" />
                    </div>
                    <div style="flex: 1 1 150px;">
                        <x-ui.select name="sync_status" :label="__('Sync')" :options="$syncOptions" :value="$filters['sync_status']" :placeholder="__('Any sync state')" />
                    </div>
                    <div style="flex: 0 1 130px;">
                        <x-ui.select name="per_page" :label="__('Per page')" :options="$perPageOptions" :value="$filters['per_page']" />
                    </div>
                </div>
            </div>

            <div class="card-footer flex-center gap-3">
                <x-ui.button type="submit" icon="search">{{ __('Apply filters') }}</x-ui.button>
                <x-ui.button :href="route('sales.index')" variant="ghost">{{ __('Reset') }}</x-ui.button>
                <span class="spacer"></span>
                <span class="fs-sm text-muted">{{ __('Showing :count of :total entries', ['count' => $sales->count(), 'total' => \App\Support\Formatter::number($sales->total())]) }}</span>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Invoice') }}</th>
                    <th>{{ __('Sold at') }}</th>
                    <th>{{ __('Customer') }}</th>
                    <th>{{ __('Cashier') }}</th>
                    <th class="text-right">{{ __('Grand total') }}</th>
                    <th>{{ __('Payment') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Source / Sync') }}</th>
                    <th class="cell-actions">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sales as $sale)
                    <tr>
                        <td>
                            <a href="{{ route('sales.show', $sale) }}" class="fw-semibold font-mono">{{ $sale->invoice_number }}</a>
                            @if ($sale->status === 'completed' && ! \App\Support\Money::isZero((string) $sale->due_total))
                                <div class="fs-xs text-warning financial">
                                    {{ __('Due :amount', ['amount' => \App\Support\Formatter::money($sale->due_total)]) }}
                                </div>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            <div>{{ \App\Support\Formatter::dateTime($sale->sold_at) }}</div>
                            <div class="fs-xs text-muted">{{ $sale->warehouse?->name ?? '—' }}</div>
                        </td>
                        <td>
                            @if ($sale->customer)
                                <div class="fw-medium">{{ $sale->customer->name }}</div>
                                <div class="fs-xs text-muted">{{ $sale->customer->phone ?: '—' }}</div>
                            @else
                                <span class="text-muted">{{ __('Walk-in customer') }}</span>
                            @endif
                        </td>
                        <td>
                            <div>{{ $sale->user?->name ?? '—' }}</div>
                            <div class="fs-xs text-muted">{{ $sale->branch?->name ?? '—' }}</div>
                        </td>
                        <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($sale->grand_total) }}</td>
                        <td>@include('sales.partials.payment-status', ['status' => $sale->payment_status])</td>
                        <td>@include('sales.partials.sale-status', ['status' => $sale->status])</td>
                        <td>@include('sales.partials.sync-status', ['sync' => $sale->sync_status, 'source' => $sale->source])</td>
                        <td class="cell-actions">
                            <div class="flex-center gap-2" style="justify-content: flex-end;">
                                <x-ui.button :href="route('sales.show', $sale)" variant="ghost" size="sm" icon="eye" class="table-actions-btn" :aria-label="__('View sale :number', ['number' => $sale->invoice_number])" />
                                <x-ui.button :href="route('sales.invoice', $sale)" variant="ghost" size="sm" icon="print" class="table-actions-btn" :aria-label="__('Print invoice :number', ['number' => $sale->invoice_number])" />

                                @can('sales.refund')
                                    @if (in_array($sale->status, ['completed', 'returned'], true))
                                        <x-ui.button :href="route('sales.return.form', $sale)" variant="ghost" size="sm" icon="refresh" class="table-actions-btn" :aria-label="__('Return items from :number', ['number' => $sale->invoice_number])" />
                                    @endif
                                @endcan

                                @can('sales.void')
                                    @if ($sale->status === 'completed')
                                        <form method="POST" action="{{ route('sales.void', $sale) }}" data-prevent-double
                                              onsubmit="return confirm('{{ __('Void sale :number? Stock and ledger entries will be reversed.', ['number' => $sale->invoice_number]) }}');">
                                            @csrf
                                            <input type="hidden" name="reason" value="{{ __('Voided from the sales register') }}">
                                            <x-ui.button variant="danger-ghost" size="sm" icon="x" class="table-actions-btn" :aria-label="__('Void :number', ['number' => $sale->invoice_number])" />
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <x-ui.empty-state icon="receipt" :title="$hasFilters ? __('No sales match these filters') : __('No sales recorded yet')" :text="$hasFilters ? __('Try widening the date range or clearing the search term.') : __('Sales created at the point of sale will appear here.')">
                                @can('pos.access')
                                    <x-ui.button :href="route('pos.index')" icon="pos">{{ __('Open Point of Sale') }}</x-ui.button>
                                @endcan
                            </x-ui.empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {!! $sales->links('partials.pagination') !!}

</x-app-layout>
