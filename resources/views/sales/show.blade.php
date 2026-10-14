<x-app-layout>
    <x-slot:title>{{ __('Sale :number', ['number' => $sale->invoice_number]) }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header
        :back="route('sales.index')"
        :title="$sale->invoice_number"
        :subtitle="__('Sold :date · :customer', [
            'date' => \App\Support\Formatter::dateTime($sale->sold_at),
            'customer' => $sale->customer?->name ?? __('Walk-in customer'),
        ])"
    >
        <x-slot:actions>
            <x-ui.button :href="route('sales.invoice', $sale)" variant="outline" icon="print">{{ __('Invoice / Print') }}</x-ui.button>

            @can('sales.refund')
                @if (in_array($sale->status, ['completed', 'returned'], true))
                    <x-ui.button :href="route('sales.return.form', $sale)" icon="refresh">{{ __('Return Items') }}</x-ui.button>
                @endif
            @endcan

            @can('sales.void')
                @if ($sale->status === 'completed')
                    <x-ui.button variant="danger" icon="x" data-modal-open="#void-sale-modal">{{ __('Void Sale') }}</x-ui.button>
                @endif
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex-center gap-3 mb-4" style="flex-wrap: wrap;">
        @include('sales.partials.sale-status', ['status' => $sale->status])
        @include('sales.partials.payment-status', ['status' => $sale->payment_status])
        @include('sales.partials.sync-status', ['sync' => $sale->sync_status, 'source' => $sale->source])

        <span class="spacer"></span>

        <span class="fs-sm text-muted">{{ __('Grand total') }}</span>
        <span class="financial fw-bold" style="font-size: var(--fs-xl);">{{ \App\Support\Formatter::money($sale->grand_total) }}</span>
    </div>

    @if ($sale->status === 'voided')
        <x-ui.alert type="danger" :title="__('This sale was voided')">
            {{ __('Stock has been returned to :warehouse and the related journal entries were reversed.', ['warehouse' => $sale->warehouse?->name ?? '—']) }}
        </x-ui.alert>
    @elseif ($sale->status === 'returned')
        <x-ui.alert type="info" :title="__('This sale has returns')">
            {{ __('All items on this sale have been returned. See the linked return documents below.') }}
        </x-ui.alert>
    @endif

    {{-- Document information --}}
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Sale information') }}</h2>
            <a href="{{ route('sales.invoice', $sale) }}" class="fs-sm">{{ __('View printable invoice') }}</a>
        </div>
        <div class="card-body">
            <div class="flex gap-4" style="flex-wrap: wrap;">
                <div style="flex: 1 1 260px;">
                    <table class="data-table">
                        <tbody>
                            <tr>
                                <td class="text-muted">{{ __('Customer') }}</td>
                                <td class="text-right fw-medium">
                                    @if ($sale->customer)
                                        {{ $sale->customer->name }}
                                        <div class="fs-xs text-muted">{{ $sale->customer->phone ?: '—' }}</div>
                                    @else
                                        {{ __('Walk-in customer') }}
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">{{ __('Cashier') }}</td>
                                <td class="text-right fw-medium">{{ $sale->user?->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <td class="text-muted">{{ __('Branch') }}</td>
                                <td class="text-right fw-medium">{{ $sale->branch?->name ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div style="flex: 1 1 260px;">
                    <table class="data-table">
                        <tbody>
                            <tr>
                                <td class="text-muted">{{ __('Warehouse') }}</td>
                                <td class="text-right fw-medium">{{ $sale->warehouse?->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <td class="text-muted">{{ __('Sold at') }}</td>
                                <td class="text-right fw-medium">{{ \App\Support\Formatter::dateTime($sale->sold_at) }}</td>
                            </tr>
                            <tr>
                                <td class="text-muted">{{ __('Register session') }}</td>
                                <td class="text-right fw-medium">{{ $sale->cashRegisterSession?->id ? '#' . $sale->cashRegisterSession->id : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($sale->notes)
                <div class="mt-3">
                    <div class="form-label">{{ __('Notes') }}</div>
                    <p class="fs-sm text-secondary m-0">{{ $sale->notes }}</p>
                </div>
            @endif
        </div>
    </div>

    {{-- Line items --}}
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Items') }}</h2>
            <span class="fs-sm text-muted">{{ __(':count lines', ['count' => \App\Support\Formatter::number($sale->items->count())]) }}</span>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>{{ __('Product') }}</th>
                        <th>{{ __('SKU') }}</th>
                        <th class="text-right">{{ __('Qty') }}</th>
                        <th class="text-right">{{ __('Returned') }}</th>
                        <th class="text-right">{{ __('Unit price') }}</th>
                        <th class="text-right">{{ __('Discount') }}</th>
                        <th class="text-right">{{ __('Tax') }}</th>
                        <th class="text-right">{{ __('Line total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sale->items as $item)
                        <tr>
                            <td class="fw-medium">{{ $item->product_name }}</td>
                            <td class="font-mono fs-sm text-muted">{{ $item->product_sku ?: '—' }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::number($item->quantity, 4) }}</td>
                            <td class="num financial">
                                @php $returnedQty = (string) ($returned[$item->id] ?? '0'); @endphp
                                @if (\App\Support\Money::isZero($returnedQty))
                                    <span class="text-muted">—</span>
                                @else
                                    <span class="text-warning">{{ \App\Support\Formatter::number($returnedQty, 4) }}</span>
                                @endif
                            </td>
                            <td class="num financial">{{ \App\Support\Formatter::money($item->unit_price) }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::money($item->discount_amount) }}</td>
                            <td class="num financial">
                                {{ \App\Support\Formatter::money($item->tax_amount) }}
                                @if (! \App\Support\Money::isZero((string) $item->tax_rate))
                                    <div class="fs-xs text-muted">{{ \App\Support\Formatter::number($item->tax_rate, 2) }}%</div>
                                @endif
                            </td>
                            <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($item->line_total) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <x-ui.empty-state icon="box" :title="__('No items on this sale')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Totals & payments --}}
    <div class="flex gap-4 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 340px;">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('Totals') }}</h2>
                </div>
                <div class="card-body">
                    <div class="flex-between mb-2">
                        <span class="text-muted">{{ __('Subtotal') }}</span>
                        <span class="financial fw-medium">{{ \App\Support\Formatter::money($sale->subtotal) }}</span>
                    </div>
                    <div class="flex-between mb-2">
                        <span class="text-muted">{{ __('Discount') }}</span>
                        <span class="financial fw-medium">{{ \App\Support\Formatter::money($sale->discount_total) }}</span>
                    </div>
                    <div class="flex-between mb-2">
                        <span class="text-muted">{{ __('Tax') }}</span>
                        <span class="financial fw-medium">{{ \App\Support\Formatter::money($sale->tax_total) }}</span>
                    </div>
                    <div class="flex-between mb-2">
                        <span class="text-muted">{{ __('Shipping') }}</span>
                        <span class="financial fw-medium">{{ \App\Support\Formatter::money($sale->shipping_total) }}</span>
                    </div>
                    <div class="flex-between mb-2">
                        <span class="text-muted">{{ __('Paid') }}</span>
                        <span class="financial fw-medium text-success">{{ \App\Support\Formatter::money($sale->paid_total) }}</span>
                    </div>
                    <div class="flex-between">
                        <span class="text-muted">{{ __('Change given') }}</span>
                        <span class="financial fw-medium">{{ \App\Support\Formatter::money($sale->change_total) }}</span>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="flex-between mb-2">
                        <span class="fw-semibold">{{ __('Grand total') }}</span>
                        <span class="financial fw-bold">{{ \App\Support\Formatter::money($sale->grand_total) }}</span>
                    </div>
                    <div class="flex-between">
                        <span class="fw-semibold">{{ __('Outstanding due') }}</span>
                        <span class="financial fw-bold {{ \App\Support\Money::isZero((string) $sale->due_total) ? 'text-success' : 'text-danger' }}">
                            {{ \App\Support\Formatter::money($sale->due_total) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div style="flex: 1 1 340px;">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('Payments') }}</h2>
                    <span class="fs-sm text-muted">{{ __(':count transactions', ['count' => \App\Support\Formatter::number($sale->payments->count())]) }}</span>
                </div>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>{{ __('Method') }}</th>
                                <th>{{ __('Reference') }}</th>
                                <th class="text-right">{{ __('Amount') }}</th>
                                <th class="text-right">{{ __('Change') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sale->payments as $payment)
                                <tr>
                                    <td class="fw-medium">{{ $payment->paymentMethod?->name ?? '—' }}</td>
                                    <td class="fs-sm text-muted">{{ $payment->reference ?: '—' }}</td>
                                    <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($payment->amount) }}</td>
                                    <td class="num financial">{{ \App\Support\Formatter::money($payment->change_amount) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        <x-ui.empty-state icon="credit-card" :title="__('No payments recorded')" />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>


    {{-- Linked returns --}}
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Linked returns') }}</h2>
            <a href="{{ route('sales.returns') }}" class="fs-sm">{{ __('All returns') }}</a>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>{{ __('Return #') }}</th>
                        <th>{{ __('Returned at') }}</th>
                        <th class="text-right">{{ __('Lines') }}</th>
                        <th>{{ __('Restock') }}</th>
                        <th>{{ __('Reason') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="text-right">{{ __('Refunded') }}</th>
                        <th class="cell-actions">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sale->returns as $return)
                        <tr>
                            <td class="font-mono fw-semibold">{{ $return->return_number }}</td>
                            <td class="text-nowrap">{{ \App\Support\Formatter::dateTime($return->returned_at) }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::number($return->items->count()) }}</td>
                            <td>
                                <x-ui.badge :type="$return->restock ? 'success' : 'default'">
                                    {{ $return->restock ? __('Restocked') : __('Not restocked') }}
                                </x-ui.badge>
                            </td>
                            <td class="fs-sm text-muted">{{ $return->reason ?: '—' }}</td>
                            <td>@include('sales.partials.return-status', ['status' => $return->status])</td>
                            <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($return->refunded_total) }}</td>
                            <td class="cell-actions">
                                <div class="flex-center gap-2" style="justify-content: flex-end;">
                                    <x-ui.button :href="route('sales.returns.show', $return)" variant="ghost" size="sm" icon="eye" class="table-actions-btn" :aria-label="__('View return :number', ['number' => $return->return_number])" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <x-ui.empty-state icon="refresh" :title="__('No returns against this sale')" :text="__('Returned items, refunds and restocks will be listed here.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Ledger --}}
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Ledger entries') }}</h2>
            <span class="fs-sm text-muted">{{ __('Posted journal activity for this sale') }}</span>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>{{ __('Entry #') }}</th>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Description') }}</th>
                        <th class="text-right">{{ __('Debit') }}</th>
                        <th class="text-right">{{ __('Credit') }}</th>
                        <th>{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($journalEntries as $entry)
                        <tr>
                            <td class="font-mono fw-medium">{{ $entry->entry_number }}</td>
                            <td class="text-nowrap">{{ \App\Support\Formatter::date($entry->entry_date) }}</td>
                            <td class="fs-sm">{{ $entry->description ?: '—' }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::money($entry->total_debit) }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::money($entry->total_credit) }}</td>
                            <td>
                                <x-ui.badge :type="$entry->status === 'posted' ? 'success' : 'warning'">{{ __(ucfirst((string) $entry->status)) }}</x-ui.badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state icon="calculator" :title="__('No ledger entries')" :text="__('Journal entries appear once the chart of accounts is seeded.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @can('sales.void')
        @if ($sale->status === 'completed')
            <x-ui.modal id="void-sale-modal" :title="__('Void sale :number', ['number' => $sale->invoice_number])" size="md">
                <x-ui.alert type="warning" :dismissible="false">
                    {{ __('Voiding returns all :count lines to stock and reverses every journal entry posted for this sale. This cannot be undone.', ['count' => \App\Support\Formatter::number($sale->items->count())]) }}
                </x-ui.alert>

                <form method="POST" action="{{ route('sales.void', $sale) }}" id="void-sale-form" data-prevent-double>
                    @csrf
                    <x-ui.input name="reason" :label="__('Reason for voiding')" :value="old('reason')" :placeholder="__('e.g. Duplicate sale, wrong customer, cancelled order')" :required="true" :hint="__('Stored on the sale and in the audit log.')" />
                </form>

                <x-slot:footer>
                    <div class="flex-center gap-3">
                        <x-ui.button type="submit" form="void-sale-form" variant="danger" icon="x">{{ __('Void Sale') }}</x-ui.button>
                        <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                    </div>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    @endcan
</x-app-layout>


