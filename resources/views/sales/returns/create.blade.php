<x-app-layout>
    <x-slot:title>{{ __('Return items') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @php
        $reasonOptions = $reasons;
    @endphp

    @include('partials.flash')

    <x-ui.page-header :title="__('Return items')" :subtitle="__('Return against invoice :number', ['number' => $sale->invoice_number])"
                     :back="route('sales.show', $sale)" />

    <x-ui.alert type="info" class="mb-4">
        {{ __('Only the quantity still returnable is shown. Saving restocks the warehouse, reverses the ledger effect and records a credit note. The original sale is never altered.') }}
    </x-ui.alert>

    <form method="POST" action="{{ route('sales.return.store', $sale) }}" data-prevent-double>
        @csrf

        <div class="card mb-4">
            <div class="card-header"><h2 class="card-title">{{ __('Items') }}</h2></div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('Product') }}</th>
                            <th class="num">{{ __('Sold') }}</th>
                            <th class="num">{{ __('Already returned') }}</th>
                            <th class="num">{{ __('Returnable') }}</th>
                            <th class="num" style="width: 150px;">{{ __('Return now') }}</th>
                            <th class="num">{{ __('Refund') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $anyReturnable = false; @endphp
                        @foreach ($sale->items as $item)
                            @php
                                $itemId = $item->id;
                                $sold = (float) $item->quantity;
                                $already = (float) ($returned[$itemId] ?? 0);
                                $canReturn = (float) ($returnable[$itemId] ?? 0);
                                $anyReturnable = $anyReturnable || $canReturn > 0;
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-medium">{{ $item->product_name }}</div>
                                    <div class="fs-xs text-muted font-mono">{{ $item->product_sku }}</div>
                                </td>
                                <td class="num financial">{{ \App\Support\Formatter::number($sold, 2) }}</td>
                                <td class="num financial">{{ \App\Support\Formatter::number($already, 2) }}</td>
                                <td class="num financial">
                                    @if ($canReturn > 0)
                                        <span class="fw-semibold">{{ \App\Support\Formatter::number($canReturn, 2) }}</span>
                                    @else
                                        <span class="text-muted">{{ __('Fully returned') }}</span>
                                    @endif
                                </td>
                                <td class="num">
                                    @if ($canReturn > 0)
                                        <input type="number"
                                               name="items[{{ $itemId }}][quantity]"
                                               class="form-control text-end"
                                               step="0.0001" min="0" max="{{ $canReturn }}"
                                               value="0"
                                               aria-label="{{ __('Quantity to return for :name', ['name' => $item->product_name]) }}">
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="num financial">@if ($canReturn > 0)
                                        {{ \App\Support\Formatter::money($item->line_total) }}
                                    @else
                                        —
                                    @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h2 class="card-title">{{ __('Return details') }}</h2></div>
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 1 1 280px;">
                        <x-ui.select name="reason" :label="__('Reason')" :options="$reasonOptions" :value="old('reason')"
                                     :placeholder="__('Choose a reason')"
                                     :hint="__('Shown on the credit note and the audit trail.')" />
                    </div>
                    <div style="flex: 1 1 100%;">
                        {{-- The hidden 0 is what makes an unticked box post 0 rather
                             than nothing; without it, unticking posts no value and
                             the stored setting survives. --}}
                        <input type="hidden" name="restock" value="0">

                        <x-ui.checkbox name="restock" value="1"
                                       :label="__('Return the goods to stock')"
                                       :hint="__('Uncheck for damaged goods or items that cannot be resold.')"
                                       :checked="old('restock', true)" />
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <x-ui.button type="submit" variant="danger" icon="refresh" :disabled="! $anyReturnable">
                {{ __('Process return') }}
            </x-ui.button>
            <x-ui.button :href="route('sales.show', $sale)" variant="outline">{{ __('Cancel') }}</x-ui.button>
            <span class="spacer"></span>
            @unless ($anyReturnable)
                <span class="fs-sm text-warning">{{ __('Every line on this sale has already been returned.') }}</span>
            @endunless
        </div>
    </form>
</x-app-layout>
