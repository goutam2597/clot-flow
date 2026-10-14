<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :subtitle="__('Every drawer open and close, with the cash difference recorded')">
        <x-slot:actions>
            <x-ui.button variant="primary" icon="plus" :href="route('pos.sessions.create')">{{ __('Open Session') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('partials.flash')

    <form method="GET" action="{{ route('pos.sessions.index') }}" class="filter-bar">
        <x-ui.select
            name="status"
            :label="null"
            :options="['open' => __('Open'), 'closed' => __('Closed')]"
            :value="$status"
            :placeholder="__('All statuses')"
        />
        <x-ui.select
            name="cash_register_id"
            :label="null"
            :options="$registers"
            :value="$registerId ?: null"
            :placeholder="__('All registers')"
        />
        <span class="spacer"></span>
        <x-ui.button variant="outline" type="submit" icon="search">{{ __('Filter') }}</x-ui.button>
    </form>

    <div class="table-wrap">
        @if ($sessions->isEmpty())
            <div class="table-empty">
                <x-ui.empty-state
                    icon="calculator"
                    :title="__('No register sessions')"
                    :text="__('Open a session on a register to start recording sales.')"
                >
                    <x-ui.button variant="primary" icon="plus" :href="route('pos.sessions.create')">{{ __('Open Session') }}</x-ui.button>
                </x-ui.empty-state>
            </div>
        @else
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('Register') }}</th>
                            <th>{{ __('Cashier') }}</th>
                            <th>{{ __('Opened') }}</th>
                            <th>{{ __('Closed') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="num">{{ __('Sales') }}</th>
                            <th class="num">{{ __('Takings') }}</th>
                            <th class="num">{{ __('Difference') }}</th>
                            <th class="cell-actions">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions as $session)
                            <tr>
                                <td class="fw-medium">{{ $session->cashRegister?->name ?? '—' }}</td>
                                <td>{{ $session->user?->name ?? '—' }}</td>
                                <td class="fs-sm">{{ \App\Support\Formatter::dateTime($session->opened_at) }}</td>
                                <td class="fs-sm">
                                    {{ $session->closed_at ? \App\Support\Formatter::dateTime($session->closed_at) : '—' }}
                                </td>
                                <td>
                                    @if ($session->status === 'open')
                                        <x-ui.badge type="warning" :dot="true">{{ __('Open') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge type="success">{{ __('Closed') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="num financial">{{ \App\Support\Formatter::number($session->sale_count, 0) }}</td>
                                <td class="num financial">{{ \App\Support\Formatter::money($session->total_sales) }}</td>
                                <td class="num financial {{ ! is_null($session->cash_difference) && (float) $session->cash_difference < 0 ? 'text-danger' : '' }}">
                                    {{ is_null($session->cash_difference) ? '—' : \App\Support\Formatter::money($session->cash_difference) }}
                                </td>
                                <td class="cell-actions">
                                    <x-ui.button variant="ghost" size="sm" icon="eye" :href="route('pos.sessions.show', $session)">
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

    {{ $sessions->links('partials.pagination') }}
</x-layouts.app>
