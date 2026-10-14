<x-app-layout>
    <x-slot:title>{{ __('Profit & loss') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    <x-ui.page-header :title="__('Profit & loss')" :subtitle="__('Revenue less cost of sales and operating expenses, from posted ledger activity only.')"
                     :back="route('accounting.index')">
        <x-slot:actions>
            <x-ui.button variant="outline" icon="print" onclick="window.print()">{{ __('Print') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="card mb-4">
        <form method="GET" action="{{ route('accounting.profit-loss') }}">
            <div class="card-body">
                <div class="field-bar">
                    <div style="flex: 1 1 190px;">
                        <x-ui.input name="from" type="date" id="from" :label="__('From')" :value="$from" />
                    </div>
                    <div style="flex: 1 1 190px;">
                        <x-ui.input name="to" type="date" id="to" :label="__('Until')" :value="$to" />
                    </div>
                    <div>
                        <x-ui.button type="submit" icon="filter">{{ __('Apply') }}</x-ui.button>
                    </div>
                </div>
            </div>
            <div class="card-footer">
                <p class="fs-sm text-muted mb-0">
                    {{ __('Period: :from to :to', ['from' => \App\Support\Formatter::date($from), 'to' => \App\Support\Formatter::date($to)]) }}
                </p>
            </div>
        </form>
    </div>

    {{-- Headline figures --}}
    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="trending-up" :label="__('Revenue')" :value="\App\Support\Formatter::money($revenue)" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="box" :label="__('Cost of goods sold')" :value="\App\Support\Formatter::money($cogs)" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="calculator" :label="__('Gross profit')" :value="\App\Support\Formatter::money($grossProfit)"
                            :hint="__('Margin :margin%', ['margin' => \App\Support\Formatter::number(
                                \App\Support\Money::isZero($revenue) ? '0'
                                    : \App\Support\Money::div(\App\Support\Money::mul($grossProfit, '100', 4), $revenue, 1)
                            )])" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="wallet" :label="__('Operating expenses')" :value="\App\Support\Formatter::money($expenses)" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="chart" :label="__('Net profit')" :value="\App\Support\Formatter::money($netProfit)"
                            :hint="__('Margin :margin%', ['margin' => \App\Support\Formatter::number($margin)])" />
        </div>
    </div>

    {{-- Statement --}}
    <div class="card print-only-hidden">
        <div class="card-header"><h2 class="card-title">{{ __('Statement detail') }}</h2></div>
        <div class="card-body">
            <table class="data-table">
                <tbody>
                    <tr style="background: var(--bg-surface-2);">
                        <td colspan="2" class="fw-semibold">{{ __('Revenue') }}</td>
                    </tr>
                    @forelse ($revenueAccounts as $row)
                        <tr>
                            <td>
                                <a href="{{ route('accounting.general-ledger', ['account' => $row['account']->id, 'from' => $from, 'to' => $to]) }}">
                                    {{ $row['account']->name }}
                                </a>
                                <span class="fs-xs text-muted font-mono">{{ $row['account']->code }}</span>
                            </td>
                            <td class="num financial">{{ \App\Support\Formatter::money($row['balance'], false) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-muted">{{ __('No revenue posted in this period.') }}</td></tr>
                    @endforelse
                    <tr style="font-weight: 600; background: var(--bg-surface-2);">
                        <td>{{ __('Total revenue') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($revenue, false) }}</td>
                    </tr>

                    <tr style="background: var(--bg-surface-2);">
                        <td colspan="2" class="fw-semibold">{{ __('Costs') }}</td>
                    </tr>
                    @forelse ($expenseAccounts as $row)
                        <tr>
                            <td>
                                <a href="{{ route('accounting.general-ledger', ['account' => $row['account']->id, 'from' => $from, 'to' => $to]) }}">
                                    {{ $row['account']->name }}
                                </a>
                                <span class="fs-xs text-muted font-mono">{{ $row['account']->code }}</span>
                            </td>
                            <td class="num financial">{{ \App\Support\Formatter::money($row['balance'], false) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-muted">{{ __('No costs posted in this period.') }}</td></tr>
                    @endforelse
                    <tr style="font-weight: 600; background: var(--bg-surface-2);">
                        <td>{{ __('Total costs') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money(\App\Support\Money::add($cogs, $expenses, 2), false) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr style="font-weight: 700; background: var(--bg-surface-3);">
                        <td>{{ __('Net profit') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($netProfit, false) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- Print-only business identity --}}
    <div class="print-only mt-4">
        <p class="fs-xs text-muted mb-0">
            {{ \App\Support\Formatter::setting('legal_name', config('app.name')) }}
            @if ($tax = \App\Support\Formatter::setting('tax_registration_number'))
                · {{ __('Tax registration :number', ['number' => $tax]) }}
            @endif
            · {{ __('Generated :date', ['date' => now()->format('Y-m-d H:i')]) }}
        </p>
    </div>
</x-app-layout>
