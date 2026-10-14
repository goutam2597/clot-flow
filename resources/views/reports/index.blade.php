<x-app-layout>
    <x-slot:title>{{ __('Reports') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Reports & analytics')" :subtitle="__('Every figure is computed live from posted transactions.')" />

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="trending-up" :label="__('Today\'s sales')" :value="\App\Support\Formatter::money($todayRevenue)"
                            :hint="trans_choice(':count transaction|:count transactions', $todayCount, ['count' => \App\Support\Formatter::number($todayCount)])" />
        </div>
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="chart" :label="__('This month')" :value="\App\Support\Formatter::money($monthRevenue)" />
        </div>
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="money" :label="__('Today\'s gross profit')" :value="\App\Support\Formatter::money($todayProfit)" />
        </div>
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="alert" :label="__('Low stock lines')" :value="\App\Support\Formatter::number($lowStock)" />
        </div>
    </div>

    <div class="flex gap-3" style="flex-wrap: wrap;">
        <div style="flex: 1 1 340px;">
            <x-ui.button :href="route('reports.sales')" variant="outline" icon="sales" size="lg" block>
                {{ __('Sales analysis') }}
            </x-ui.button>
            <p class="fs-sm text-muted mt-2">
                {{ __('Daily revenue, top-selling products and the payment mix over a date range.') }}
            </p>
        </div>
        <div style="flex: 1 1 340px;">
            <x-ui.button :href="route('reports.profit-loss')" variant="outline" icon="trending-up" size="lg" block>
                {{ __('Profit & loss') }}
            </x-ui.button>
            <p class="fs-sm text-muted mt-2">
                {{ __('Revenue, cost of goods sold, discounts, tax and expenses for a period.') }}
            </p>
        </div>
        <div style="flex: 1 1 340px;">
            <x-ui.button :href="route('reports.inventory')" variant="outline" icon="box" size="lg" block>
                {{ __('Inventory health') }}
            </x-ui.button>
            <p class="fs-sm text-muted mt-2">
                {{ __('Stock valuation, low-stock lines and slow movers across every warehouse.') }}
            </p>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header"><h2 class="card-title">{{ __('Ledger reports') }}</h2></div>
        <div class="card-body">
            <p class="fs-sm text-muted">
                {{ __('These reports read directly from the general ledger, so they always reconcile with the accounting module.') }}
            </p>
            <div class="flex gap-3 mt-3" style="flex-wrap: wrap;">
                @can('accounting.view')
                    <x-ui.button :href="route('accounting.trial-balance')" variant="outline" icon="calculator">{{ __('Trial balance') }}</x-ui.button>
                    <x-ui.button :href="route('accounting.profit-loss')" variant="outline" icon="trending-up">{{ __('Ledger P&L') }}</x-ui.button>
                    <x-ui.button :href="route('accounting.balance-sheet')" variant="outline" icon="file">{{ __('Balance sheet') }}</x-ui.button>
                    <x-ui.button :href="route('accounting.general-ledger')" variant="outline" icon="list">{{ __('General ledger') }}</x-ui.button>
                @endcan
                @can('pos.access')
                    <x-ui.button :href="route('sync.status')" variant="outline" icon="sync">{{ __('Offline sync failures') }}</x-ui.button>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
