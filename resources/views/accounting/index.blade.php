<x-app-layout>
    <x-slot:title>{{ __('Accounting') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Accounting')" :subtitle="__('Every figure below is derived from posted journal entries. Posted entries are never edited — corrections are made with reversing entries.')">
        <x-slot:actions>
            <x-ui.button :href="route('accounting.profit-loss')" variant="outline" icon="trending-up">{{ __('Profit & loss') }}</x-ui.button>
            <x-ui.button :href="route('accounting.balance-sheet')" variant="outline" icon="file">{{ __('Balance sheet') }}</x-ui.button>
            <x-ui.button :href="route('accounting.trial-balance')" variant="outline" icon="calculator">{{ __('Trial balance') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Position by statement section --}}
    <div class="card mb-4">
        <div class="card-header flex-between">
            <h2 class="card-title">{{ __('Position by account type') }}</h2>
            <form method="GET" action="{{ route('accounting.index') }}" class="flex-center gap-2">
                <label class="sr-only" for="as_of">{{ __('As of') }}</label>
                <input type="date" id="as_of" name="as_of" value="{{ $asOf }}" class="form-control" style="width: 170px;">
                <x-ui.button type="submit" icon="filter">{{ __('Apply') }}</x-ui.button>
            </form>
        </div>
        <div class="card-body">
            <div class="flex gap-3" style="flex-wrap: wrap;">
                @foreach ($balances as $type => $amount)
                    @php
                        $meta = [
                            'asset' => ['label' => __('Assets'), 'icon' => 'briefcase', 'type' => 'primary'],
                            'liability' => ['label' => __('Liabilities'), 'icon' => 'file', 'type' => 'warning'],
                            'equity' => ['label' => __('Equity'), 'icon' => 'shield', 'type' => 'info'],
                            'revenue' => ['label' => __('Revenue'), 'icon' => 'trending-up', 'type' => 'success'],
                            'expense' => ['label' => __('Expenses'), 'icon' => 'trending-down', 'type' => 'danger'],
                        ][$type];
                    @endphp
                    <div style="flex: 1 1 180px;">
                        <x-ui.stat-card :icon="$meta['icon']" :label="$meta['label']"
                                        :value="\App\Support\Formatter::money($amount)"
                                        :hint="$asOf ? __('As of :date', ['date' => \App\Support\Formatter::date($asOf)]) : __('All posted entries')" />
                    </div>
                @endforeach
            </div>

            <p class="fs-sm text-muted mt-4 mb-0">
                {{ __('Liabilities and equity should equal total assets. Check the balance sheet to see any difference caused by unclosed periods.') }}
            </p>
        </div>
    </div>

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 190px;"><x-ui.stat-card icon="layers" :label="__('Accounts')" :value="\App\Support\Formatter::number($accountCount)" /></div>
        <div style="flex: 1 1 190px;"><x-ui.stat-card icon="file" :label="__('Journal entries')" :value="\App\Support\Formatter::number($entryCount)" /></div>
    </div>

    {{-- Statement shortcuts --}}
    <div class="card mb-4">
        <div class="card-header"><h2 class="card-title">{{ __('Reports') }}</h2></div>
        <div class="card-body">
            <div class="flex gap-3" style="flex-wrap: wrap;">
                <x-ui.button :href="route('accounting.accounts')" variant="outline" icon="list" style="flex: 1 1 200px;">{{ __('Chart of accounts') }}</x-ui.button>
                <x-ui.button :href="route('accounting.journals')" variant="outline" icon="receipt" style="flex: 1 1 200px;">{{ __('Journal entries') }}</x-ui.button>
                <x-ui.button :href="route('accounting.trial-balance')" variant="outline" icon="calculator" style="flex: 1 1 200px;">{{ __('Trial balance') }}</x-ui.button>
                <x-ui.button :href="route('accounting.general-ledger')" variant="outline" icon="book" style="flex: 1 1 200px;">{{ __('General ledger') }}</x-ui.button>
                <x-ui.button :href="route('accounting.profit-loss')" variant="outline" icon="trending-up" style="flex: 1 1 200px;">{{ __('Profit & loss') }}</x-ui.button>
                <x-ui.button :href="route('accounting.balance-sheet')" variant="outline" icon="file" style="flex: 1 1 200px;">{{ __('Balance sheet') }}</x-ui.button>
            </div>
        </div>
    </div>

    {{-- Recent activity --}}
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Entry') }}</th>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Description') }}</th>
                    <th>{{ __('Posted by') }}</th>
                    <th class="num">{{ __('Debit') }}</th>
                    <th class="num">{{ __('Credit') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="cell-actions"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentEntries as $entry)
                    <tr>
                        <td class="fw-semibold font-mono">
                            <a href="{{ route('accounting.journals.show', $entry) }}">{{ $entry->entry_number }}</a>
                        </td>
                        <td class="text-nowrap">{{ \App\Support\Formatter::date($entry->entry_date) }}</td>
                        <td>
                            <div>{{ $entry->description ?: '—' }}</div>
                            <div class="fs-xs text-muted">{{ class_basename((string) $entry->reference_type) }}</div>
                        </td>
                        <td class="fs-sm">{{ $entry->user?->name ?? __('System') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($entry->total_debit, false) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($entry->total_credit, false) }}</td>
                        <td>
                            @if ($entry->status === 'reversed')
                                <x-ui.badge type="warning">{{ __('Reversed') }}</x-ui.badge>
                            @elseif ($entry->isBalanced())
                                <x-ui.badge type="success" dot>{{ __('Posted') }}</x-ui.badge>
                            @else
                                <x-ui.badge type="danger">{{ __('Unbalanced') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="cell-actions">
                            <x-ui.button :href="route('accounting.journals.show', $entry)" variant="ghost" size="sm" icon="eye" class="table-actions-btn"
                                         :aria-label="__('View entry :number', ['number' => $entry->entry_number])" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-ui.empty-state icon="calculator" :title="__('No journal entries yet')"
                                            :text="__('Entries appear as soon as a sale, purchase or expense is recorded.')" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
