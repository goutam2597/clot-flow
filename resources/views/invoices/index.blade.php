<x-app-layout>
    <x-slot:title>{{ __('Invoices') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @php
        $statusOptions = collect($statuses)->mapWithKeys(fn ($s) => [$s => __(ucfirst($s))])->all();
        $paymentOptions = collect($paymentStatuses)->mapWithKeys(fn ($s) => [$s => __(ucfirst($s))])->all();
    @endphp

    @include('partials.flash')

    <x-ui.page-header :title="__('Invoices')" :subtitle="__('Issued from completed sales. Invoices are read-only documents — the sale remains the source of truth.')" />

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="receipt" :label="__('Invoices in filter')" :value="\App\Support\Formatter::number($totals['count'])"
                            :hint="\App\Support\Formatter::money($totals['grand_total'])" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="money" :label="__('Settled')" :value="\App\Support\Formatter::money($totals['paid_total'])" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="alert" :label="__('Outstanding')" :value="\App\Support\Formatter::money($totals['due_total'])" />
        </div>
    </div>

    <div class="card mb-4">
        <form method="GET" action="{{ route('invoices.index') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 250px;">
                        <x-ui.input name="search" :label="__('Search')" :value="$filters['search'] ?? ''"
                                    :placeholder="__('Invoice number or customer')" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.select name="status" :label="__('Status')" :options="$statusOptions" :value="$filters['status'] ?? ''"
                                     :placeholder="__('Any status')" />
                    </div>
                    <div style="flex: 1 1 170px;">
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
                <x-ui.button :href="route('invoices.index')" variant="ghost">{{ __('Reset') }}</x-ui.button>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Invoice') }}</th>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Customer') }}</th>
                    <th>{{ __('Branch') }}</th>
                    <th class="num">{{ __('Total') }}</th>
                    <th>{{ __('Payment') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="cell-actions">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoices as $invoice)
                    <tr>
                        <td class="fw-semibold font-mono">
                            <a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a>
                        </td>
                        <td class="text-nowrap">{{ \App\Support\Formatter::dateTime($invoice->sold_at) }}</td>
                        <td>
                            @if ($invoice->customer)
                                {{ $invoice->customer->name }}
                            @else
                                <span class="text-muted">{{ __('Walk-in customer') }}</span>
                            @endif
                        </td>
                        <td class="fs-sm">{{ $invoice->branch?->name ?? '—' }}</td>
                        <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($invoice->grand_total) }}</td>
                        <td>@include('sales.partials.payment-status', ['status' => $invoice->payment_status])</td>
                        <td>@include('sales.partials.sale-status', ['status' => $invoice->status])</td>
                        <td class="cell-actions">
                            <div class="flex-center gap-2" style="justify-content: flex-end;">
                                <x-ui.button :href="route('invoices.show', $invoice)" variant="ghost" size="sm" icon="eye" class="table-actions-btn"
                                             :aria-label="__('View :number', ['number' => $invoice->invoice_number])" />
                                <x-ui.button :href="route('sales.invoice', $invoice)" variant="ghost" size="sm" icon="print" class="table-actions-btn"
                                             :aria-label="__('Print :number', ['number' => $invoice->invoice_number])" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-ui.empty-state icon="receipt" :title="__('No invoices match these filters')"
                                            :text="__('An invoice is raised automatically for every completed sale.')" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {!! $invoices->links('partials.pagination') !!}
</x-app-layout>
