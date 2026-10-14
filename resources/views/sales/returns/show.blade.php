<x-app-layout>
    <x-slot:title>{{ __('Return :number', ['number' => $saleReturn->return_number]) }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="$saleReturn->return_number" :subtitle="__('Return against invoice :number', ['number' => $saleReturn->sale?->invoice_number ?? '—'])"
                     :back="route('sales.returns')">
        <x-slot:actions>
            <x-ui.button :href="route('sales.show', $saleReturn->sale)" variant="outline" icon="arrow-left">{{ __('Original sale') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex gap-4 mb-4" style="flex-wrap: wrap; align-items: flex-start;">
        <div style="flex: 1 1 360px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Details') }}</h2></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <tbody>
                            <tr><th style="width: 42%;">{{ __('Return number') }}</th>
                                <td class="font-mono">{{ $saleReturn->return_number }}</td></tr>
                            <tr><th>{{ __('Original invoice') }}</th>
                                <td class="font-mono">{{ $saleReturn->sale?->invoice_number ?? '—' }}</td></tr>
                            <tr><th>{{ __('Date') }}</th>
                                <td>{{ \App\Support\Formatter::dateTime($saleReturn->returned_at) }}</td></tr>
                            <tr><th>{{ __('Customer') }}</th>
                                <td>{{ $saleReturn->customer?->name ?? __('Walk-in customer') }}</td></tr>
                            <tr><th>{{ __('Processed by') }}</th>
                                <td>{{ $saleReturn->user?->name ?? '—' }}</td></tr>
                            <tr><th>{{ __('Branch') }}</th>
                                <td>{{ $saleReturn->branch?->name ?? '—' }}</td></tr>
                            <tr><th>{{ __('Warehouse') }}</th>
                                <td>{{ $saleReturn->warehouse?->name ?? '—' }}</td></tr>
                            <tr><th>{{ __('Restocked') }}</th>
                                <td>{{ $saleReturn->restock ? __('Yes') : __('No') }}</td></tr>
                            <tr><th>{{ __('Reason') }}</th>
                                <td>{{ $saleReturn->reason ?: '—' }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div style="flex: 1 1 300px;">
            <div class="card mb-4">
                <div class="card-header"><h2 class="card-title">{{ __('Amounts') }}</h2></div>
                <div class="card-body">
                    <div class="summary-row">
                        <span class="text-secondary">{{ __('Return value') }}</span>
                        <span class="financial">{{ \App\Support\Formatter::money($saleReturn->grand_total) }}</span>
                    </div>
                    <div class="summary-row">
                        <span class="text-secondary">{{ __('Tax reversed') }}</span>
                        <span class="financial">{{ \App\Support\Formatter::money($saleReturn->tax_total) }}</span>
                    </div>
                    <div class="summary-row">
                        <span class="text-secondary">{{ __('Refunded') }}</span>
                        <span class="financial">{{ \App\Support\Formatter::money($saleReturn->refunded_total) }}</span>
                    </div>
                    <div class="summary-row is-grand">
                        <span class="fw-semibold">{{ __('Status') }}</span>
                        <x-ui.badge :type="$saleReturn->status === 'completed' ? 'success' : 'warning'">
                            {{ __(ucfirst($saleReturn->status)) }}
                        </x-ui.badge>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Item') }}</th>
                    <th class="num">{{ __('Sold') }}</th>
                    <th class="num">{{ __('Returned') }}</th>
                    <th class="num">{{ __('Unit price') }}</th>
                    <th class="num">{{ __('Discount') }}</th>
                    <th class="num">{{ __('Tax') }}</th>
                    <th class="num">{{ __('Refund') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($saleReturn->items as $item)
                    <tr>
                        <td>
                            <div class="fw-medium">{{ $item->product_name }}</div>
                            <div class="fs-xs text-muted font-mono">{{ $item->product_sku }}</div>
                        </td>
                        <td class="num financial">{{ \App\Support\Formatter::number($item->quantity, 2) }}</td>
                        <td class="num financial fw-semibold">{{ \App\Support\Formatter::number($item->quantity, 2) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($item->unit_price) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($item->discount_amount) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($item->tax_amount) }}</td>
                        <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($item->line_total) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">{{ __('This return has no lines.') }}</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr style="font-weight: 700; background: var(--bg-surface-2);">
                    <td colspan="6">{{ __('Total') }}</td>
                    <td class="num financial">{{ \App\Support\Formatter::money($saleReturn->refunded_total) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Ledger --}}
    <div class="card mt-4">
        <div class="card-header"><h2 class="card-title">{{ __('Ledger entries') }}</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>{{ __('Entry') }}</th>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Description') }}</th>
                        <th class="num">{{ __('Debit') }}</th>
                        <th class="num">{{ __('Credit') }}</th>
                        <th class="cell-actions"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($journalEntries as $entry)
                        <tr>
                            <td class="font-mono fw-medium">{{ $entry->entry_number }}</td>
                            <td class="text-nowrap">{{ \App\Support\Formatter::date($entry->entry_date) }}</td>
                            <td class="fs-sm">{{ $entry->description ?: '—' }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::money($entry->total_debit, false) }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::money($entry->total_credit, false) }}</td>
                            <td class="cell-actions">
                                <x-ui.button :href="route('accounting.journals.show', $entry)" variant="ghost" size="sm" icon="eye" class="table-actions-btn"
                                             :aria-label="__('View entry :number', ['number' => $entry->entry_number])" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state icon="calculator" :title="__('No ledger entries')"
                                                :text="__('A reversing entry is posted when the return is processed.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
