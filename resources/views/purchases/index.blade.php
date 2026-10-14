<x-app-layout>
    <x-slot:title>{{ __('Purchases') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @php
        $statusOptions = collect($statuses)->mapWithKeys(fn ($s) => [$s => __(ucfirst($s))])->all();
        $paymentOptions = collect($paymentStatuses)->mapWithKeys(fn ($s) => [$s => __(ucfirst($s))])->all();
    @endphp

    @include('partials.flash')

    <x-ui.page-header :title="__('Purchases')" :subtitle="__('Orders and goods receipts. Receiving stock updates inventory and accounts payable in one transaction.')">
        <x-slot:actions>
            @can('purchases.create')
                <x-ui.button :href="route('purchases.create')" icon="plus">{{ __('New purchase') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="truck" :label="__('Documents in filter')"
                            :value="\App\Support\Formatter::number($totals['count'])"
                            :hint="\App\Support\Formatter::money($totals['grand_total'])" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="money" :label="__('Paid')" :value="\App\Support\Formatter::money($totals['paid_total'])" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="alert" :label="__('Outstanding payable')" :value="\App\Support\Formatter::money($totals['due_total'])" />
        </div>
    </div>

    <div class="card mb-4">
        <form method="GET" action="{{ route('purchases.index') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 250px;">
                        <x-ui.input name="search" :label="__('Search')" :value="$filters['search'] ?? ''"
                                    :placeholder="__('Purchase number or supplier')" />
                    </div>
                    <div style="flex: 1 1 200px;">
                        <x-ui.select name="supplier_id" :label="__('Supplier')" :options="$suppliers" :value="$filters['supplier_id'] ?? ''"
                                     :placeholder="__('All suppliers')" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.select name="status" :label="__('Status')" :options="$statusOptions" :value="$filters['status'] ?? ''"
                                     :placeholder="__('Any status')" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.select name="payment_status" :label="__('Payment')" :options="$paymentOptions" :value="$filters['payment_status'] ?? ''"
                                     :placeholder="__('Any payment state')" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.input name="from" type="date" :label="__('From')" :value="$filters['from'] ?? ''" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.input name="to" type="date" :label="__('Until')" :value="$filters['to'] ?? ''" />
                    </div>
                </div>
            </div>
            <div class="card-footer flex-center gap-3">
                <x-ui.button type="submit" icon="search">{{ __('Apply filters') }}</x-ui.button>
                <x-ui.button :href="route('purchases.index')" variant="ghost">{{ __('Reset') }}</x-ui.button>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Purchase') }}</th>
                    <th>{{ __('Supplier') }}</th>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Warehouse') }}</th>
                    <th class="num">{{ __('Grand total') }}</th>
                    <th>{{ __('Payment') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="cell-actions">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($purchases as $purchase)
                    <tr>
                        <td>
                            <a href="{{ route('purchases.show', $purchase) }}" class="fw-semibold font-mono">{{ $purchase->purchase_number }}</a>
                            @if (! \App\Support\Money::isZero((string) $purchase->due_total))
                                <div class="fs-xs text-warning financial">
                                    {{ __('Due :amount', ['amount' => \App\Support\Formatter::money($purchase->due_total)]) }}
                                </div>
                            @endif
                        </td>
                        <td>{{ $purchase->supplier?->name ?? '—' }}</td>
                        <td class="text-nowrap">{{ \App\Support\Formatter::date($purchase->purchased_at) }}</td>
                        <td>
                            <div>{{ $purchase->warehouse?->name ?? '—' }}</div>
                            <div class="fs-xs text-muted">{{ $purchase->branch?->name ?? '—' }}</div>
                        </td>
                        <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($purchase->grand_total) }}</td>
                        <td>
                            <x-ui.badge :type="$purchase->payment_status === 'paid' ? 'success' : 'warning'">
                                {{ __(ucfirst($purchase->payment_status)) }}
                            </x-ui.badge>
                        </td>
                        <td>
                            <x-ui.badge :type="$purchase->status === 'received' ? 'success' : 'info'" dot>
                                {{ __(ucfirst($purchase->status)) }}
                            </x-ui.badge>
                        </td>
                        <td class="cell-actions">
                            <div class="flex-center gap-2" style="justify-content: flex-end;">
                                <x-ui.button :href="route('purchases.show', $purchase)" variant="ghost" size="sm" icon="eye" class="table-actions-btn"
                                             :aria-label="__('View :number', ['number' => $purchase->purchase_number])" />

                                @can('purchases.receive')
                                    @if ($purchase->status !== 'received')
                                        <form method="POST" action="{{ route('purchases.receive', $purchase) }}" data-prevent-double
                                              onsubmit="return confirm('{{ __('Receive :number into stock? This cannot be undone.', ['number' => $purchase->purchase_number]) }}');">
                                            @csrf
                                            <x-ui.button type="submit" variant="outline" size="sm" icon="download"
                                                         :aria-label="__('Receive :number', ['number' => $purchase->purchase_number])" />
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-ui.empty-state icon="truck" :title="__('No purchases match these filters')"
                                            :text="__('Record a purchase to bring stock into a warehouse and raise a payable.')">
                                @can('purchases.create')
                                    <x-ui.button :href="route('purchases.create')" icon="plus">{{ __('New purchase') }}</x-ui.button>
                                @endcan
                            </x-ui.empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {!! $purchases->links('partials.pagination') !!}
</x-app-layout>
