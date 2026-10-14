<x-app-layout extra-styles="css/invoice.css">
    <x-slot:title>{{ __('Invoice :number', ['number' => $sale->invoice_number]) }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header
        :back="route('sales.show', $sale)"
        :title="__('Invoice :number', ['number' => $sale->invoice_number])"
        :subtitle="__('Sold :date', ['date' => \App\Support\Formatter::dateTime($sale->sold_at)])"
    >
        <x-slot:actions>
            @include('sales.partials.sale-status', ['status' => $sale->status])
            @include('sales.partials.payment-status', ['status' => $sale->payment_status])
            <x-ui.button variant="outline" icon="print" onclick="window.print();">{{ __('Print') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{--
        The invoice renders as a thermal receipt: paper-white, ~80mm column,
        centred header, dashed tear lines, money always in the right column.
        All rendering styles live in css/invoice.css (loaded in the head via
        extra-styles, so there is no restyle flash on refresh); the print
        rules in the same file strip the admin shell and hand the page to the
        paper.
    --}}
    <article class="invoice-paper" aria-label="{{ __('Invoice :number', ['number' => $sale->invoice_number]) }}">

        {{-- Seller --}}
        <header class="iv-head">
            @php
                $receiptLogoType = \App\Support\BrandLogo::type();
                // An uploaded logo rides above the name, like a printed
                // letterhead; SVG stays inline so it prints crisp.
                $receiptLogoSvg = $receiptLogoType === 'svg' ? \App\Support\BrandLogo::svg() : null;
                $receiptLogoUrl = $receiptLogoType === 'png' ? \App\Support\BrandLogo::url() : null;

                // White-label regulatory lines: each country names and
                // structures these differently, so they are free-text
                // settings and blank simply means "not printed".
                $receiptGroup = 'invoice';
                $headerLine1 = trim((string) \App\Support\Formatter::setting('invoice_header_line_1', '', $receiptGroup));
                $headerLine2 = trim((string) \App\Support\Formatter::setting('invoice_header_line_2', '', $receiptGroup));
                $vatNumber = trim((string) \App\Support\Formatter::setting('invoice_vat_number', '', $receiptGroup));
                $vatLabel = trim((string) \App\Support\Formatter::setting('invoice_vat_label', '', $receiptGroup)) ?: __('VAT No.');
                $chalanNumber = trim((string) \App\Support\Formatter::setting('invoice_chalan_number', '', $receiptGroup));
                $chalanType = trim((string) \App\Support\Formatter::setting('invoice_chalan_type', '', $receiptGroup));
                $terminalId = trim((string) \App\Support\Formatter::setting('invoice_terminal_id', '', $receiptGroup));
            @endphp
            @if ($headerLine1 !== '')
                <div class="iv-line">{{ $headerLine1 }}</div>
            @endif
            @if ($headerLine2 !== '')
                <div class="iv-line">{{ $headerLine2 }}</div>
            @endif
            @if ($receiptLogoSvg)
                <span class="iv-logo">{!! $receiptLogoSvg !!}</span>
            @elseif ($receiptLogoUrl)
                <img class="iv-logo" src="{{ $receiptLogoUrl }}" alt="">
            @else
                {{-- No logo uploaded: the business name carries the head alone. --}}
                <div class="iv-business">{{ config('app.name', 'Meridian POS') }}</div>
            @endif
            @if ($sale->branch)
                <div class="iv-line">{{ $sale->branch->name }}</div>
                @if ($sale->branch->address_line)
                    <div class="iv-line">{{ $sale->branch->address_line }}@if ($sale->branch->city), {{ $sale->branch->city }}@endif</div>
                @endif
                @if ($sale->branch->phone || $sale->branch->email)
                    <div class="iv-line">
                        @if ($sale->branch->phone){{ __('Tel') }}: {{ $sale->branch->phone }}@endif
                        @if ($sale->branch->email) · {{ $sale->branch->email }}@endif
                    </div>
                @endif
            @endif
            @if ($vatNumber !== '')
                <div class="iv-line">{{ $vatLabel }}: {{ $vatNumber }}</div>
            @endif
            @if (\App\Support\Formatter::setting('tax_registration_number'))
                <div class="iv-line">{{ __('Tax registration number') }}: {{ \App\Support\Formatter::setting('tax_registration_number') }}</div>
            @endif
        </header>

        <hr class="iv-cut">

        {{-- Invoice meta --}}
        <div class="iv-row">
            <span class="iv-label">{{ __('Invoice number') }}</span>
            <span class="iv-strong">{{ $sale->invoice_number }}</span>
        </div>
        <div class="iv-row">
            <span class="iv-label">{{ __('Invoice date') }}</span>
            <span>{{ \App\Support\Formatter::dateTime($sale->sold_at) }}</span>
        </div>
        <div class="iv-row">
            <span class="iv-label">{{ __('Cashier') }}</span>
            <span>{{ $sale->user?->name ?? '—' }}</span>
        </div>
        <div class="iv-row">
            <span class="iv-label">{{ __('Branch / Warehouse') }}</span>
            <span>{{ $sale->warehouse?->name ?? '—' }}</span>
        </div>
        @if ($chalanNumber !== '')
            <div class="iv-row">
                <span class="iv-label">{{ __('Chalan No.') }}@if ($chalanType !== '') ({{ $chalanType }})@endif</span>
                <span>{{ $chalanNumber }}</span>
            </div>
        @endif
        @if ($terminalId !== '')
            <div class="iv-row">
                <span class="iv-label">{{ __('Terminal ID') }}</span>
                <span>{{ $terminalId }}</span>
            </div>
        @endif

        {{-- Bill to --}}
        <div class="iv-row">
            <span class="iv-label">{{ __('Bill to') }}</span>
            <span class="iv-strong">
                @if ($sale->customer)
                    {{ $sale->customer->name }}
                    @if ($sale->customer->phone) · {{ $sale->customer->phone }}@endif
                @else
                    {{ __('Walk-in customer') }}
                @endif
            </span>
        </div>

        <hr class="iv-cut">

        {{-- Items --}}
        <ul class="iv-items">
            @forelse ($sale->items as $item)
                <li class="iv-item">
                    <div class="iv-item-head">
                        <span>{{ $item->product_name }}</span>
                        <span class="iv-money">{{ \App\Support\Formatter::money($item->line_total) }}</span>
                    </div>
                    <div class="iv-item-sub">
                        <span>{{ \App\Support\Formatter::number($item->quantity) }} × {{ \App\Support\Formatter::money($item->unit_price, false) }}</span>
                        <span>
                            @if ((float) $item->discount_amount > 0)
                                {{ __('Discount') }} {{ \App\Support\Formatter::money($item->discount_amount, false) }}
                            @elseif ((float) $item->tax_rate > 0)
                                {{ __('Tax') }} {{ \App\Support\Formatter::number($item->tax_rate) }}%
                            @endif
                        </span>
                    </div>
                </li>
            @empty
                <li class="iv-item">{{ __('This sale has no line items') }}</li>
            @endforelse
        </ul>

        @if ($sale->notes)
            <div class="iv-line" style="margin-top: 10px; color: #555;">
                {{ __('Memo') }}: {{ $sale->notes }}
            </div>
        @endif

        <hr class="iv-cut">

        {{-- Totals --}}
        <div class="iv-totals">
            <div class="iv-row">
                <span class="iv-label">{{ __('Subtotal') }}</span>
                <span class="iv-money">{{ \App\Support\Formatter::money($sale->subtotal) }}</span>
            </div>
            @if ((float) $sale->discount_total > 0)
                <div class="iv-row">
                    <span class="iv-label">{{ __('Discount') }}</span>
                    <span class="iv-money">−{{ \App\Support\Formatter::money($sale->discount_total) }}</span>
                </div>
            @endif
            @if ((float) $sale->tax_total > 0)
                <div class="iv-row">
                    <span class="iv-label">{{ __('Tax') }}</span>
                    <span class="iv-money">{{ \App\Support\Formatter::money($sale->tax_total) }}</span>
                </div>
            @endif
            @if ((float) $sale->shipping_total > 0)
                <div class="iv-row">
                    <span class="iv-label">{{ __('Shipping') }}</span>
                    <span class="iv-money">{{ \App\Support\Formatter::money($sale->shipping_total) }}</span>
                </div>
            @endif

            <div class="iv-row iv-grand">
                <span>{{ __('Total') }}</span>
                <span class="iv-money">{{ \App\Support\Formatter::money($sale->grand_total) }}</span>
            </div>

            @foreach ($sale->payments as $payment)
                <div class="iv-row">
                    <span class="iv-label">{{ $payment->paymentMethod?->name ?? __('Payment') }}</span>
                    <span class="iv-money">{{ \App\Support\Formatter::money($payment->amount) }}</span>
                </div>
            @endforeach

            @if ((float) $sale->change_total > 0)
                <div class="iv-row">
                    <span class="iv-label">{{ __('Change due') }}</span>
                    <span class="iv-money">{{ \App\Support\Formatter::money($sale->change_total) }}</span>
                </div>
            @elseif ((float) $sale->due_total > 0)
                <div class="iv-row iv-strong">
                    <span class="iv-label">{{ __('Balance due') }}</span>
                    <span class="iv-money">{{ \App\Support\Formatter::money($sale->due_total) }}</span>
                </div>
            @endif
        </div>

        {{-- Returns / credit notes against this sale --}}
        @if ($sale->returns->count())
            <hr class="iv-cut">
            <div class="iv-row iv-strong">
                <span>{{ __('Linked returns') }}</span>
            </div>
            @foreach ($sale->returns as $saleReturn)
                <div class="iv-row">
                    <span class="iv-label">{{ $saleReturn->return_number }} · {{ \App\Support\Formatter::date($saleReturn->returned_at) }}</span>
                    <span class="iv-money">−{{ \App\Support\Formatter::money($saleReturn->refunded_total) }}</span>
                </div>
            @endforeach
        @endif

        <hr class="iv-cut">

        {{-- Footer --}}
        <footer class="iv-footer">
            @if (\App\Support\Formatter::setting('pos_receipt_footer'))
                <div>{{ \App\Support\Formatter::setting('pos_receipt_footer') }}</div>
            @endif
            <div>{{ __('Thank you for your purchase.') }}</div>
            <div class="iv-stamp">
                {{ __('Printed :date', ['date' => \App\Support\Formatter::dateTime(now())]) }} · {{ config('app.name', 'Meridian POS') }}
            </div>
        </footer>
    </article>
</x-app-layout>
