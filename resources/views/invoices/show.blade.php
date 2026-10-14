<x-app-layout>
    <x-slot:title>{{ __('Invoice :number', ['number' => $sale->invoice_number]) }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="$sale->invoice_number" :subtitle="__('Invoice issued from sale :number', ['number' => $sale->invoice_number])">
        <x-slot:actions>
            <x-ui.button :href="route('sales.show', $sale)" variant="outline" icon="arrow-left">{{ __('Back to sale') }}</x-ui.button>
            <x-ui.button :href="route('sales.invoice', $sale)" variant="primary" icon="print">{{ __('Print invoice') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex gap-4" style="flex-wrap: wrap; align-items: flex-start;">
        {{-- Business identity --}}
        <div style="flex: 1 1 300px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('From') }}</h2></div>
                <div class="card-body">
                    <div class="fw-semibold">{{ \App\Support\Formatter::setting('legal_name', config('app.name')) }}</div>
                    @if ($address = trim(implode(', ', array_filter([
                        \App\Support\Formatter::setting('address_line'),
                        \App\Support\Formatter::setting('city'),
                        \App\Support\Formatter::setting('country'),
                    ]))))
                        <div class="fs-sm text-muted">{{ $address }}</div>
                    @endif
                    @if ($taxNumber = \App\Support\Formatter::setting('tax_registration_number'))
                        <div class="fs-sm text-muted">{{ __('Tax registration :number', ['number' => $taxNumber]) }}</div>
                    @endif
                    @if ($email = \App\Support\Formatter::setting('email'))
                        <div class="fs-sm text-muted">{{ $email }}</div>
                    @endif
                    @if ($phone = \App\Support\Formatter::setting('phone'))
                        <div class="fs-sm text-muted">{{ $phone }}</div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Customer --}}
        <div style="flex: 1 1 300px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Bill to') }}</h2></div>
                <div class="card-body">
                    @if ($sale->customer)
                        <div class="fw-semibold">{{ $sale->customer->name }}</div>
                        @if ($sale->customer->address)
                            <div class="fs-sm text-muted">{{ $sale->customer->address }}</div>
                        @endif
                        @if ($sale->customer->phone)
                            <div class="fs-sm text-muted">{{ $sale->customer->phone }}</div>
                        @endif
                        @if ($sale->customer->tax_number)
                            <div class="fs-sm text-muted">{{ __('Tax number :number', ['number' => $sale->customer->tax_number]) }}</div>
                        @endif
                    @else
                        <span class="text-muted">{{ __('Walk-in customer') }}</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Document meta --}}
        <div style="flex: 1 1 300px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Details') }}</h2></div>
                <div class="card-body">
                    <table class="data-table">
                        <tbody>
                            <tr>
                                <th style="width: 45%;">{{ __('Invoice number') }}</th>
                                <td class="font-mono">{{ $sale->invoice_number }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Date') }}</th>
                                <td>{{ \App\Support\Formatter::dateTime($sale->sold_at) }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Branch') }}</th>
                                <td>{{ $sale->branch?->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Warehouse') }}</th>
                                <td>{{ $sale->warehouse?->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Served by') }}</th>
                                <td>{{ $sale->user?->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Payment') }}</th>
                                <td>@include('sales.partials.payment-status', ['status' => $sale->payment_status])</td>
                            </tr>
                            <tr>
                                <th>{{ __('Status') }}</th>
                                <td>@include('sales.partials.sale-status', ['status' => $sale->status])</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Lines --}}
    <div class="table-wrap mt-4">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Item') }}</th>
                    <th class="num">{{ __('Quantity') }}</th>
                    <th class="num">{{ __('Unit price') }}</th>
                    <th class="num">{{ __('Discount') }}</th>
                    <th class="num">{{ __('Tax') }}</th>
                    <th class="num">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sale->items as $item)
                    <tr>
                        <td>
                            <div class="fw-medium">{{ $item->product_name }}</div>
                            <div class="fs-xs text-muted font-mono">{{ $item->product_sku }}</div>
                        </td>
                        <td class="num financial">{{ \App\Support\Formatter::number($item->quantity, 2) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($item->unit_price) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($item->discount_amount) }}</td>
                        <td class="num financial">
                            {{ \App\Support\Formatter::money($item->tax_amount) }}
                            <div class="fs-xs text-muted">{{ \App\Support\Formatter::number($item->tax_rate) }}%</div>
                        </td>
                        <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($item->line_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" class="text-right">{{ __('Subtotal') }}</td>
                    <td class="num financial">{{ \App\Support\Formatter::money($sale->subtotal) }}</td>
                </tr>
                @if (! \App\Support\Money::isZero((string) $sale->discount_total))
                    <tr>
                        <td colspan="5" class="text-right">{{ __('Discount') }}</td>
                        <td class="num financial text-danger">−{{ \App\Support\Formatter::money($sale->discount_total) }}</td>
                    </tr>
                @endif
                <tr>
                    <td colspan="5" class="text-right">{{ __('Tax') }}</td>
                    <td class="num financial">{{ \App\Support\Formatter::money($sale->tax_total) }}</td>
                </tr>
                @if (! \App\Support\Money::isZero((string) $sale->shipping_total))
                    <tr>
                        <td colspan="5" class="text-right">{{ __('Shipping') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($sale->shipping_total) }}</td>
                    </tr>
                @endif
                <tr style="font-weight: 700; background: var(--bg-surface-2);">
                    <td colspan="5" class="text-right">{{ __('Grand total') }}</td>
                    <td class="num financial">{{ \App\Support\Formatter::money($sale->grand_total) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="flex gap-4 mt-4" style="flex-wrap: wrap; align-items: flex-start;">
        {{-- Payments --}}
        <div style="flex: 1 1 360px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Payments') }}</h2></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>{{ __('Method') }}</th>
                                <th>{{ __('Reference') }}</th>
                                <th class="num">{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sale->payments as $payment)
                                <tr>
                                    <td>{{ $payment->paymentMethod?->name ?? '—' }}</td>
                                    <td class="fs-sm font-mono">{{ $payment->reference ?: '—' }}</td>
                                    <td class="num financial">{{ \App\Support\Formatter::money($payment->amount) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">{{ __('No payment recorded.') }}</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr style="font-weight: 600; background: var(--bg-surface-2);">
                                <td colspan="2">{{ __('Paid') }}</td>
                                <td class="num financial">{{ \App\Support\Formatter::money($sale->paid_total) }}</td>
                            </tr>
                            @if (! \App\Support\Money::isZero((string) $sale->due_total))
                                <tr style="font-weight: 600;">
                                    <td colspan="2" class="text-warning">{{ __('Outstanding') }}</td>
                                    <td class="num financial text-warning">{{ \App\Support\Formatter::money($sale->due_total) }}</td>
                                </tr>
                            @endif
                            @if (! \App\Support\Money::isZero((string) $sale->change_total))
                                <tr>
                                    <td colspan="2">{{ __('Change given') }}</td>
                                    <td class="num financial">{{ \App\Support\Formatter::money($sale->change_total) }}</td>
                                </tr>
                            @endif
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        {{-- Notes / terms --}}
        <div style="flex: 1 1 300px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Notes') }}</h2></div>
                <div class="card-body">
                    @if ($sale->notes)
                        <p class="fs-sm">{{ $sale->notes }}</p>
                    @else
                        <p class="fs-sm text-muted">{{ __('No note was recorded for this sale.') }}</p>
                    @endif

                    @if ($terms = \App\Support\Formatter::setting('invoice_terms'))
                        <div class="mt-3" style="padding-top: var(--space-3); border-top: 1px solid var(--border-color);">
                            <div class="fs-xs text-muted fw-semibold">{{ __('Terms & conditions') }}</div>
                            <div class="fs-xs text-muted">{{ $terms }}</div>
                        </div>
                    @endif

                    @if ($footer = \App\Support\Formatter::setting('invoice_footer'))
                        <div class="mt-3">
                            <div class="fs-xs text-muted">{{ $footer }}</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
