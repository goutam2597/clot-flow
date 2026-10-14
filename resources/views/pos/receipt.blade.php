{{--
    The receipt screen.

    Deliberately thin: every figure on this page comes from the thermal partial,
    so what the cashier checks before pressing Print is exactly what the roll
    will say. A separate "print version" is how a receipt ends up carrying a
    line nobody reviewed on screen.
--}}
<x-layouts.app :title="$title">
    @php
        $rtl = in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur'], true);
    @endphp

    @push('styles')
        <link rel="stylesheet" href="{{ \App\Support\Asset::url('css/receipt.css') }}">
    @endpush

    <x-ui.page-header
        :title="$title"
        :subtitle="__('Sold :date by :cashier', [
            'date' => \App\Support\Formatter::dateTime($sale->sold_at),
            'cashier' => $sale->user?->name ?? '—',
        ])"
        :back="route('pos.index')"
    >
        <x-slot:actions>
            {{-- The whole page is the receipt, so printing is just this. --}}
            <x-ui.button variant="outline" icon="print" onclick="window.print()">{{ __('Print') }}</x-ui.button>
            <x-ui.button variant="primary" icon="pos" :href="route('pos.index')">{{ __('New Sale') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('partials.flash')

    <div class="receipt-sheet">
        @include('pos.receipt-thermal', ['sale' => $sale, 'rtl' => $rtl])
    </div>
</x-layouts.app>
