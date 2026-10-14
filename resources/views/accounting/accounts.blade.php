<x-app-layout>
    <x-slot:title>{{ __('Chart of accounts') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Chart of accounts')" :subtitle="__('Balances are computed from posted journal lines, not stored on the account.')" :back="route('accounting.index')" />

    <div class="card mb-4">
        <form method="GET" action="{{ route('accounting.accounts') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 260px;">
                        <x-ui.input name="search" :label="__('Search')" :value="$filters['search'] ?? ''"
                                    :placeholder="__('Account name or code')" />
                    </div>
                    <div style="flex: 1 1 180px;">
                        <x-ui.select name="type" :label="__('Type')" :options="$types" :value="$filters['type'] ?? ''"
                                     :placeholder="__('All types')" />
                    </div>
                    <div style="flex: 1 1 170px;">
                        <x-ui.input name="as_of" type="date" :label="__('Balance as of')" :value="$asOf" />
                    </div>
                </div>
            </div>
            <div class="card-footer flex-center gap-3">
                <x-ui.button type="submit" icon="search">{{ __('Apply filters') }}</x-ui.button>
                <x-ui.button :href="route('accounting.accounts')" variant="ghost">{{ __('Reset') }}</x-ui.button>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Code') }}</th>
                    <th>{{ __('Account') }}</th>
                    <th>{{ __('Type') }}</th>
                    <th class="num">{{ __('Debit') }}</th>
                    <th class="num">{{ __('Credit') }}</th>
                    <th class="cell-actions"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="font-mono fw-semibold">{{ $row['account']->code }}</td>
                        <td>
                            <div>{{ $row['account']->name }}</div>
                            @if ($row['account']->description)
                                <div class="fs-xs text-muted">{{ $row['account']->description }}</div>
                            @endif
                        </td>
                        <td>
                            <x-ui.badge type="default">{{ __(ucfirst($row['account']->type)) }}</x-ui.badge>
                        </td>
                        <td class="num financial">{{ \App\Support\Formatter::money($row['debit'], false) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($row['credit'], false) }}</td>
                        <td class="cell-actions">
                            <x-ui.button :href="route('accounting.general-ledger', ['account' => $row['account']->id])"
                                         variant="ghost" size="sm" icon="list" class="table-actions-btn"
                                         :aria-label="__('Ledger for :name', ['name' => $row['account']->name])" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-ui.empty-state icon="layers" :title="__('No accounts match these filters')"
                                            :text="__('Run the chart of accounts seeder if the ledger has never been set up.')" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot>
                    <tr style="background: var(--bg-surface-2); font-weight: 600;">
                        <td colspan="3">{{ __('Totals') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($totals['debit'], false) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($totals['credit'], false) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    {!! $accounts->links('partials.pagination') !!}
</x-app-layout>
