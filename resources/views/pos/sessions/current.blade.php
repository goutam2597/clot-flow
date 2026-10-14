<x-layouts.app :title="$title">
    <x-ui.page-header
        :title="$title"
        :subtitle="__(':register · opened :time by :cashier', [
            'register' => $session->cashRegister?->name ?? '—',
            'time' => \App\Support\Formatter::dateTime($session->opened_at),
            'cashier' => $session->user?->name ?? '—',
        ])"
        :back="route('pos.index')"
    >
        <x-slot:actions>
            <x-ui.badge :type="$session->status === 'open' ? 'warning' : 'success'" :dot="true">
                {{ $session->status === 'open' ? __('Open') : __('Closed') }}
            </x-ui.badge>
            <x-ui.button variant="primary" icon="pos" :href="route('pos.index')">{{ __('Back to Terminal') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('partials.flash')

    <div class="mt-2" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--space-4);">
        <x-ui.stat-card
            :label="__('Opening float')"
            :value="\App\Support\Formatter::money($stats['opening_balance'])"
            icon="money"
        />
        <x-ui.stat-card
            :label="__('Sales count')"
            :value="\App\Support\Formatter::number($stats['sale_count'], 0)"
            icon="receipt"
        />
        <x-ui.stat-card
            :label="__('Takings')"
            :value="\App\Support\Formatter::money($stats['sales_total'])"
            icon="sales"
        />
        <x-ui.stat-card
            :label="__('Expected cash')"
            :value="\App\Support\Formatter::money($stats['expected_cash'])"
            icon="calculator"
            :hint="__('Cash received: :received · change given: :change', [
                'received' => \App\Support\Formatter::money($stats['cash_received']),
                'change' => \App\Support\Formatter::money($stats['cash_change']),
            ])"
        />
    </div>

    <div class="table-wrap mt-4">
        <div class="table-toolbar">
            <h2 class="card-title">{{ __('Recent sales in this session') }}</h2>
            <span class="text-muted fs-sm">{{ __('Showing the latest 15 transactions') }}</span>
        </div>
        @if ($sales->isEmpty())
            <div class="table-empty">
                <x-ui.empty-state
                    icon="receipt"
                    :title="__('No sales yet')"
                    :text="__('Sales completed in this session will be listed here.')"
                />
            </div>
        @else
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('Invoice') }}</th>
                            <th>{{ __('Time') }}</th>
                            <th>{{ __('Customer') }}</th>
                            <th>{{ __('Cashier') }}</th>
                            <th>{{ __('Payment') }}</th>
                            <th class="num">{{ __('Total') }}</th>
                            <th class="cell-actions">{{ __('Receipt') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sales as $sale)
                            <tr>
                                <td class="font-mono fs-sm">{{ $sale->invoice_number }}</td>
                                <td class="fs-sm">{{ \App\Support\Formatter::dateTime($sale->sold_at) }}</td>
                                <td>{{ $sale->customer?->name ?? __('Walk-in customer') }}</td>
                                <td class="fs-sm">{{ $sale->user?->name ?? '—' }}</td>
                                <td>
                                    <x-ui.badge :type="$sale->payment_status === 'paid' ? 'success' : ($sale->payment_status === 'partial' ? 'warning' : 'danger')">
                                        {{ ucfirst($sale->payment_status) }}
                                    </x-ui.badge>
                                </td>
                                <td class="num financial fw-medium">{{ \App\Support\Formatter::money($sale->grand_total) }}</td>
                                <td class="cell-actions">
                                    <x-ui.button variant="ghost" size="sm" icon="receipt" :href="route('pos.receipt', $sale)">
                                        {{ __('View') }}
                                    </x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($session->status === 'open')
        @include('pos.sessions.partials.close-form', ['session' => $session, 'stats' => $stats])
    @else
        <div class="card mt-4">
            <div class="card-header">
                <h2 class="card-title">{{ __('Closing summary') }}</h2>
            </div>
            <div class="card-body">
                <div class="flex-between gap-4 fs-sm">
                    <span class="text-muted">{{ __('Expected cash') }}</span>
                    <span class="financial">{{ \App\Support\Formatter::money($session->expected_cash) }}</span>
                </div>
                <div class="flex-between gap-4 fs-sm mt-2">
                    <span class="text-muted">{{ __('Counted closing balance') }}</span>
                    <span class="financial">{{ \App\Support\Formatter::money($session->closing_balance) }}</span>
                </div>
                <div class="flex-between gap-4 mt-3 fw-semibold">
                    <span>{{ __('Cash difference') }}</span>
                    <span class="financial {{ (float) $session->cash_difference < 0 ? 'text-danger' : 'text-success' }}">
                        {{ \App\Support\Formatter::money($session->cash_difference) }}
                    </span>
                </div>
                <div class="fs-sm text-muted mt-3">
                    {{ __('Closed :time', ['time' => \App\Support\Formatter::dateTime($session->closed_at)]) }}
                </div>
                @if ($session->notes)
                    <div class="fs-sm text-muted mt-2">{{ $session->notes }}</div>
                @endif
            </div>
        </div>
    @endif
</x-layouts.app>
