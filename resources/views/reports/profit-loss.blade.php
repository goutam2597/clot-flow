<x-app-layout>
    <x-slot:title>{{ __('Profit & loss') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Profit & loss')" :subtitle="__('Derived from completed sales and approved expenses in the selected period.')"
                     :back="route('reports.index')">
        <x-slot:actions>
            <x-ui.button variant="outline" icon="print" onclick="window.print()">{{ __('Print') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="card mb-4">
        <form method="GET" action="{{ route('reports.profit-loss') }}">
            <div class="card-body">
                <div class="field-bar">
                    <div style="flex: 1 1 190px;">
                        <x-ui.input name="from" type="date" id="from" :label="__('From')" :value="$filters['from_raw']" />
                    </div>
                    <div style="flex: 1 1 190px;">
                        <x-ui.input name="to" type="date" id="to" :label="__('Until')" :value="$filters['to_raw']" />
                    </div>
                    <div>
                        <x-ui.button type="submit" icon="filter">{{ __('Apply') }}</x-ui.button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="trending-up" :label="__('Revenue (net)')" :value="\App\Support\Formatter::money($revenue)"
                            :hint="trans_choice(':count sale|:count sales', $saleCount, ['count' => \App\Support\Formatter::number($saleCount)])" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="box" :label="__('Cost of goods sold')" :value="\App\Support\Formatter::money($cogs)" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="calculator" :label="__('Gross profit')" :value="\App\Support\Formatter::money($grossProfit)" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="wallet" :label="__('Approved expenses')" :value="\App\Support\Formatter::money($expenseTotal)" />
        </div>
        <div style="flex: 1 1 200px;">
            <x-ui.stat-card icon="chart" :label="__('Net profit')" :value="\App\Support\Formatter::money($netProfit)"
                            :hint="__('Margin :margin%', ['margin' => \App\Support\Formatter::number($margin)])" />
        </div>
    </div>

    <div class="flex gap-4" style="flex-wrap: wrap; align-items: flex-start;">
        <div style="flex: 2 1 460px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Statement') }}</h2></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <tbody>
                            <tr><td class="fw-semibold">{{ __('Revenue (net of discounts)') }}</td>
                                <td class="num financial">{{ \App\Support\Formatter::money($revenue) }}</td></tr>
                            <tr><td class="text-muted ps-4">{{ __('Less: cost of goods sold') }}</td>
                                <td class="num financial text-danger">−{{ \App\Support\Formatter::money($cogs) }}</td></tr>
                            <tr style="font-weight: 600; background: var(--bg-surface-2);">
                                <td>{{ __('Gross profit') }}</td>
                                <td class="num financial">{{ \App\Support\Formatter::money($grossProfit) }}</td></tr>
                            <tr><td class="text-muted ps-4">{{ __('Less: operating expenses') }}</td>
                                <td class="num financial text-danger">−{{ \App\Support\Formatter::money($expenseTotal) }}</td></tr>
                            <tr style="font-weight: 700; background: var(--bg-surface-3);">
                                <td>{{ __('Net profit') }}</td>
                                <td class="num financial">{{ \App\Support\Formatter::money($netProfit) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header"><h2 class="card-title">{{ __('Memo') }}</h2></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <tbody>
                            <tr><td>{{ __('Tax collected') }}</td>
                                <td class="num financial">{{ \App\Support\Formatter::money($tax) }}</td></tr>
                            <tr><td>{{ __('Discounts granted') }}</td>
                                <td class="num financial">{{ \App\Support\Formatter::money($discounts) }}</td></tr>
                            <tr><td>{{ __('Net profit margin') }}</td>
                                <td class="num financial">{{ \App\Support\Formatter::number($margin) }}%</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div style="flex: 1 1 320px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Expenses by category') }}</h2></div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>{{ __('Category') }}</th>
                                <th class="num">{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($expenseBreakdown as $row)
                                <tr>
                                    <td>{{ $row->category?->name ?? __('Uncategorised') }}</td>
                                    <td class="num financial">{{ \App\Support\Formatter::money($row->total) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2">
                                        <x-ui.empty-state icon="wallet" :title="__('No approved expenses')"
                                                        :text="__('Nothing was recorded against an expense category in this period.')" />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
