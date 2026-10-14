@php
    /**
     * The thermal receipt.
     *
     * Rendered identically on screen and on the roll, so what a cashier sees
     * before pressing Print is what comes out of the machine. A separate
     * "print version" is how a receipt ends up with a line on it that was never
     * reviewed on screen.
     *
     * This partial is deliberately self-contained: it takes a $sale and reads
     * only from it, so it can be dropped into the receipt page, a return or a
     * reprint without knowing anything about the surrounding layout.
     */
    $money = fn ($value) => \App\Support\Formatter::money($value);
    $number = fn ($value, $places = 3) => \App\Support\Formatter::number($value, $places);

    $storeName = \App\Support\Formatter::setting('store_name', config('app.name', 'Meridian POS'));
    $address = \App\Support\Formatter::setting('store_address');
    $phone = \App\Support\Formatter::setting('store_phone');

    // Absent values render as a blank row rather than "—", which on a thermal
    // head is a row of dashes the customer reads as a price.
    $optional = fn ($value) => ($value === null || $value === '') ? '' : $value;
@endphp

<div class="receipt" lang="{{ app()->getLocale() }}" dir="{{ $rtl ?? false ? 'rtl' : 'ltr' }}">
    <header class="receipt-center">
        <div class="receipt-store">{{ $storeName }}</div>

        @if ($address)
            <div class="receipt-meta">{{ $address }}</div>
        @endif

        @if ($phone)
            <div class="receipt-meta">{{ __('Tel :phone', ['phone' => $phone]) }}</div>
        @endif

        @if ($sale->branch)
            <div class="receipt-meta">{{ $sale->branch->name }}</div>
        @endif
    </header>

    <hr class="receipt-rule">

    <div class="receipt-meta">
        <div>{{ __('Invoice') }}: <strong>{{ $sale->invoice_number }}</strong></div>
        <div>{{ \App\Support\Formatter::dateTime($sale->sold_at) }}</div>

        @if ($sale->user)
            <div>{{ __('Served by :name', ['name' => $sale->user->name]) }}</div>
        @endif

        @if ($sale->cashRegisterSession?->cashRegister)
            <div>{{ __('Register :name', ['name' => $sale->cashRegisterSession->cashRegister->name]) }}</div>
        @endif
    </div>

    @if ($sale->customer)
        <div class="receipt-meta">
            <div>{{ __('Customer') }}: {{ $sale->customer->name }}</div>
            @if ($sale->customer->phone)
                <div>{{ $sale->customer->phone }}</div>
            @endif
        </div>
    @endif

    <hr class="receipt-rule">

    <table class="receipt-table">
        <thead>
            <tr>
                <th class="name">{{ __('Item') }}</th>
                <th class="num">{{ __('Qty') }}</th>
                <th class="num">{{ __('Total') }}</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($sale->items as $item)
                <tr>
                    <td class="name">
                        {{ $item->product_name }}

                        @if ($item->discount_amount > 0)
                            <div class="receipt-line-meta">
                                {{ __('Discount') }} {{ $money($item->discount_amount) }}
                            </div>
                        @endif
                    </td>

                    {{-- Unit price only when it differs from the line total:
                         on a roll, a repeated unit price is noise the customer
                         pays for in ink. --}}
                    <td class="num">
                        {{ $number($item->quantity, 3) }}
                        @if ((float) $item->quantity != 1)
                            <div class="receipt-line-meta">
                                @ {{ $money($item->unit_price) }}
                            </div>
                        @endif
                    </td>

                    <td class="num">{{ $money($item->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <hr class="receipt-rule">

    <div class="receipt-total">
        <span class="receipt-total-label">{{ __('Subtotal') }}</span>
        <span class="receipt-total-leader"></span>
        <span class="receipt-total-value">{{ $money($sale->subtotal) }}</span>
    </div>

    @if ((float) $sale->discount_total > 0)
        <div class="receipt-total">
            <span class="receipt-total-label">{{ __('Discount') }}</span>
            <span class="receipt-total-leader"></span>
            <span class="receipt-total-value">{{ $money($sale->discount_total) }}</span>
        </div>
    @endif

    @if ((float) $sale->tax_total > 0)
        <div class="receipt-total">
            <span class="receipt-total-label">{{ __('Tax') }}</span>
            <span class="receipt-total-leader"></span>
            <span class="receipt-total-value">{{ $money($sale->tax_total) }}</span>
        </div>
    @endif

    @if ((float) $sale->shipping_total > 0)
        <div class="receipt-total">
            <span class="receipt-total-label">{{ __('Shipping') }}</span>
            <span class="receipt-total-leader"></span>
            <span class="receipt-total-value">{{ $money($sale->shipping_total) }}</span>
        </div>
    @endif

    <div class="receipt-total is-grand">
        <span class="receipt-total-label">{{ __('TOTAL') }}</span>
        <span class="receipt-total-leader"></span>
        <span class="receipt-total-value">{{ $money($sale->grand_total) }}</span>
    </div>

    @if ((float) $sale->due_total > 0)
        <div class="receipt-total is-credit">
            <span class="receipt-total-label">{{ __('Balance due') }}</span>
            <span class="receipt-total-leader"></span>
            <span class="receipt-total-value">{{ $money($sale->due_total) }}</span>
        </div>
    @endif

    <hr class="receipt-rule">

    @foreach ($sale->payments as $payment)
        <div class="receipt-total">
            <span class="receipt-total-label">
                {{ $payment->paymentMethod?->name ?? __('Payment') }}
                @if ($payment->reference)
                    <span class="receipt-line-meta">{{ $optional($payment->reference) }}</span>
                @endif
            </span>
            <span class="receipt-total-leader"></span>
            <span class="receipt-total-value">{{ $money($payment->amount) }}</span>
        </div>
    @endforeach

    @if ((float) $sale->change_total > 0)
        <div class="receipt-total">
            <span class="receipt-total-label">{{ __('Change') }}</span>
            <span class="receipt-total-leader"></span>
            <span class="receipt-total-value">{{ $money($sale->change_total) }}</span>
        </div>
    @endif

    {{-- The invoice as a code the next cashier can scan straight back into the
         till, which is what makes a paper receipt worth keeping. --}}
    <div class="receipt-barcode">
        {!! \App\Support\Barcode::svg($sale->invoice_number, 56) !!}
    </div>

    <div class="receipt-center receipt-barcode-caption">{{ $sale->invoice_number }}</div>

    @if ($sale->notes)
        <div class="receipt-center receipt-meta">{{ $sale->notes }}</div>
    @endif

    <footer class="receipt-center receipt-footer">
        <div>{{ __('Thank you for your purchase.') }}</div>
        <div>{{ __('Please keep this receipt for returns.') }}</div>
    </footer>
</div>
