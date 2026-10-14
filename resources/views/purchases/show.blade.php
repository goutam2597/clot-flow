<x-app-layout>
    <x-slot:title>{{ __('Purchase :number', ['number' => $purchase->purchase_number]) }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="$purchase->purchase_number" :subtitle="__('Purchase from :supplier', ['supplier' => $purchase->supplier?->name ?? '—'])"
                     :back="route('purchases.index')">
        <x-slot:actions>
            @can('purchases.receive')
                @if ($purchase->status !== 'received')
                    <form method="POST" action="{{ route('purchases.receive', $purchase) }}" data-prevent-double
                          onsubmit="return confirm('{{ __('Receive :number into stock? This cannot be undone.', ['number' => $purchase->purchase_number]) }}');">
                        @csrf
                        <x-ui.button type="submit" variant="success" icon="download">
                            {{ __('Receive into stock') }}
                        </x-ui.button>
                    </form>
                @endif
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex gap-4 mb-4" style="flex-wrap: wrap; align-items: flex-start;">
        <div style="flex: 1 1 320px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Supplier') }}</h2></div>
                <div class="card-body">
                    @if ($purchase->supplier)
                        <div class="fw-semibold">{{ $purchase->supplier->name }}</div>
                        @if ($purchase->supplier->company)
                            <div class="fs-sm text-muted">{{ $purchase->supplier->company }}</div>
                        @endif
                        @if ($purchase->supplier->phone)
                            <div class="fs-sm text-muted">{{ $purchase->supplier->phone }}</div>
                        @endif
                        @if ($purchase->supplier->email)
                            <div class="fs-sm text-muted">{{ $purchase->supplier->email }}</div>
                        @endif
                        @if ($purchase->supplier->tax_number)
                            <div class="fs-sm text-muted">{{ __('Tax number :number', ['number' => $purchase->supplier->tax_number]) }}</div>
                        @endif
                    @else
                        <span class="text-muted">{{ __('No supplier on this document.') }}</span>
                    @endif
                </div>
            </div>
        </div>

        <div style="flex: 1 1 320px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Document') }}</h2></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <tbody>
                            <tr><th style="width: 45%;">{{ __('Purchase number') }}</th>
                                <td class="font-mono">{{ $purchase->purchase_number }}</td></tr>
                            <tr><th>{{ __('Date') }}</th>
                                <td>{{ \App\Support\Formatter::date($purchase->purchased_at) }}</td></tr>
                            <tr><th>{{ __('Branch') }}</th>
                                <td>{{ $purchase->branch?->name ?? '—' }}</td></tr>
                            <tr><th>{{ __('Warehouse') }}</th>
                                <td>{{ $purchase->warehouse?->name ?? '—' }}</td></tr>
                            <tr><th>{{ __('Recorded by') }}</th>
                                <td>{{ $purchase->user?->name ?? '—' }}</td></tr>
                            <tr><th>{{ __('Status') }}</th>
                                <td><x-ui.badge :type="$purchase->status === 'received' ? 'success' : 'info'" dot>
                                    {{ __(ucfirst($purchase->status)) }}
                                </x-ui.badge></td></tr>
                            <tr><th>{{ __('Payment') }}</th>
                                <td><x-ui.badge :type="$purchase->payment_status === 'paid' ? 'success' : 'warning'">
                                    {{ __(ucfirst($purchase->payment_status)) }}
                                </x-ui.badge></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div style="flex: 1 1 300px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Amounts') }}</h2></div>
                <div class="card-body">
                    <div class="summary-row">
                        <span class="text-secondary">{{ __('Subtotal') }}</span>
                        <span class="financial">{{ \App\Support\Formatter::money($purchase->subtotal) }}</span>
                    </div>
                    <div class="summary-row">
                        <span class="text-secondary">{{ __('Discount') }}</span>
                        <span class="financial">−{{ \App\Support\Formatter::money($purchase->discount_total) }}</span>
                    </div>
                    <div class="summary-row">
                        <span class="text-secondary">{{ __('Tax') }}</span>
                        <span class="financial">{{ \App\Support\Formatter::money($purchase->tax_total) }}</span>
                    </div>
                    <div class="summary-row">
                        <span class="text-secondary">{{ __('Shipping') }}</span>
                        <span class="financial">{{ \App\Support\Formatter::money($purchase->shipping_total) }}</span>
                    </div>
                    <div class="summary-row is-grand">
                        <span class="fw-semibold">{{ __('Grand total') }}</span>
                        <span class="financial fw-semibold">{{ \App\Support\Formatter::money($purchase->grand_total) }}</span>
                    </div>
                    <div class="summary-row">
                        <span class="text-secondary">{{ __('Paid') }}</span>
                        <span class="financial">{{ \App\Support\Formatter::money($purchase->paid_total) }}</span>
                    </div>
                    <div class="summary-row">
                        <span class="text-secondary fw-semibold">{{ __('Outstanding payable') }}</span>
                        <span class="financial fw-semibold {{ \App\Support\Money::isZero((string) $purchase->due_total) ? 'text-success' : 'text-warning' }}">
                            {{ \App\Support\Formatter::money($purchase->due_total) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($purchase->notes)
        <x-ui.alert type="info" class="mb-4">{{ $purchase->notes }}</x-ui.alert>
    @endif

    @if ($purchase->status !== 'received')
        <x-ui.alert type="warning" class="mb-4" :title="__('This purchase has not been received yet')">
            {{ __('Stock has not been added and no payable has been posted. Use “Receive into stock” once the goods arrive — the operation is idempotent, so it can safely be retried.') }}
        </x-ui.alert>
    @endif

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Item') }}</th>
                    <th class="num">{{ __('Ordered') }}</th>
                    <th class="num">{{ __('Received') }}</th>
                    <th class="num">{{ __('Unit cost') }}</th>
                    <th class="num">{{ __('Discount') }}</th>
                    <th class="num">{{ __('Tax') }}</th>
                    <th class="num">{{ __('Line total') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($purchase->items as $item)
                    <tr>
                        <td>
                            <div class="fw-medium">{{ $item->product?->name ?? '—' }}</div>
                            <div class="fs-xs text-muted font-mono">{{ $item->product?->sku }}</div>
                        </td>
                        <td class="num financial">{{ \App\Support\Formatter::number($item->quantity, 2) }}</td>
                        <td class="num financial">
                            {{ \App\Support\Formatter::number($item->received_quantity ?? 0, 2) }}
                        </td>
                        <td class="num financial">{{ \App\Support\Formatter::money($item->unit_cost) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($item->discount_amount) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($item->tax_amount) }}</td>
                        <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($item->line_total) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">{{ __('This purchase has no lines.') }}</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr style="font-weight: 700; background: var(--bg-surface-2);">
                    <td colspan="6">{{ __('Grand total') }}</td>
                    <td class="num financial">{{ \App\Support\Formatter::money($purchase->grand_total) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

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
                                @can('accounting.view')
                                    <x-ui.button :href="route('accounting.journals.show', $entry)" variant="ghost" size="sm" icon="eye" class="table-actions-btn"
                                                 :aria-label="__('View entry :number', ['number' => $entry->entry_number])" />
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state icon="calculator" :title="__('Nothing posted to the ledger yet')"
                                                :text="__('Receiving the goods posts the payable in the same transaction.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
