<x-app-layout>
    <x-slot:title>{{ __('General ledger') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('General ledger')" :subtitle="$account->name" :back="route('accounting.accounts')" />

    <div class="card mb-4">
        <form method="GET" action="{{ route('accounting.general-ledger') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 1 1 280px;">
                        <x-ui.select name="account" :label="__('Account')" required :options="$accounts->pluck('name', 'id')"
                                     :value="$account->id" />
                    </div>
                    <div style="flex: 1 1 170px;">
                        <x-ui.input name="from" type="date" :label="__('From')" :value="$filters['from']" />
                    </div>
                    <div style="flex: 1 1 170px;">
                        <x-ui.input name="to" type="date" :label="__('Until')" :value="$filters['to']" />
                    </div>
                </div>
            </div>
            <div class="card-footer flex-center gap-3">
                <x-ui.button type="submit" icon="filter">{{ __('Show ledger') }}</x-ui.button>
                <x-ui.button :href="route('accounting.general-ledger', ['account' => $account->id])" variant="ghost">{{ __('Clear dates') }}</x-ui.button>
            </div>
        </form>
    </div>

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 220px;">
            <x-ui.stat-card icon="clock" :label="__('Opening balance')" :value="\App\Support\Formatter::money($opening)" />
        </div>
        <div style="flex: 1 1 220px;">
            <x-ui.stat-card icon="arrow-left" :label="__('Total debits')" :value="\App\Support\Formatter::money($totals['debit'])" />
        </div>
        <div style="flex: 1 1 220px;">
            <x-ui.stat-card icon="arrow-left" :label="__('Total credits')" :value="\App\Support\Formatter::money($totals['credit'])" />
        </div>
        <div style="flex: 1 1 220px;">
            <x-ui.stat-card icon="calculator" :label="__('Closing balance')" :value="\App\Support\Formatter::money($closing)" />
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Entry') }}</th>
                    <th>{{ __('Description') }}</th>
                    <th class="num">{{ __('Debit') }}</th>
                    <th class="num">{{ __('Credit') }}</th>
                    <th class="num">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr style="background: var(--bg-surface-2); font-weight: 600;">
                    <td colspan="5">{{ __('Opening balance') }}</td>
                    <td class="num financial">{{ \App\Support\Formatter::money($opening, false) }}</td>
                </tr>

                @forelse ($ledgerRows as $row)
                    <tr>
                        <td class="text-nowrap">{{ \App\Support\Formatter::date($row['line']->journalEntry->entry_date) }}</td>
                        <td class="font-mono fs-sm">
                            <a href="{{ route('accounting.journals.show', $row['line']->journalEntry) }}">
                                {{ $row['line']->journalEntry->entry_number }}
                            </a>
                        </td>
                        <td>
                            <div>{{ $row['line']->description ?: $row['line']->journalEntry->description }}</div>
                            @if ($row['line']->journalEntry->status === 'reversed')
                                <x-ui.badge type="warning">{{ __('Reversed') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="num financial">{{ $row['line']->debit != 0 ? \App\Support\Formatter::money($row['line']->debit, false) : '' }}</td>
                        <td class="num financial">{{ $row['line']->credit != 0 ? \App\Support\Formatter::money($row['line']->credit, false) : '' }}</td>
                        <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($row['balance'], false) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-ui.empty-state icon="list" :title="__('No postings in this period')"
                                            :text="__('Widen the date range to see the account’s activity.')" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if ($ledgerRows->isNotEmpty())
                <tfoot>
                    <tr style="background: var(--bg-surface-3); font-weight: 700;">
                        <td colspan="3">{{ __('Period totals') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($totals['debit'], false) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($totals['credit'], false) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($closing, false) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    {!! $lines->links('partials.pagination') !!}
</x-app-layout>
