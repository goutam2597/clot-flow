<x-app-layout>
    <x-slot:title>{{ __('Trial balance') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Trial balance')" :subtitle="__('A proof that the ledger is internally consistent: total debits must equal total credits.')" :back="route('accounting.index')" />

    <div class="card mb-4">
        <form method="GET" action="{{ route('accounting.trial-balance') }}">
            <div class="card-body">
                {{-- The hint lives below the bar, not inside it: a hint inside
                     a field makes that column taller, and an end-aligned row
                     drops the button to the hint's level instead of the
                     input's. FilterBarTest fails any hint placed back in. --}}
                <div class="field-bar">
                    <div style="flex: 0 1 220px;">
                        <x-ui.input name="as_of" type="date" id="as_of" :label="__('Balance as of')" :value="$asOf" />
                    </div>
                    <div>
                        <x-ui.button type="submit" icon="filter">{{ __('Apply') }}</x-ui.button>
                    </div>
                </div>
                <p class="filter-note">{{ __('Leave empty to include every posted entry.') }}</p>
            </div>
        </form>
    </div>

    @if (! $isBalanced)
        <x-ui.alert type="danger" class="mb-4" :title="__('The trial balance does not agree')">
            {{ __('Total debits (:debits) and total credits (:credits) differ. This indicates a corrupted or manually altered ledger and should be investigated before any financial statement is relied upon.', [
                'debits' => \App\Support\Formatter::money($totals['debit']),
                'credits' => \App\Support\Formatter::money($totals['credit']),
            ]) }}
        </x-ui.alert>
    @endif

    <div class="card mb-4">
        <div class="card-header flex-between">
            <h2 class="card-title">{{ __('Summary') }}</h2>
            <x-ui.badge :type="$isBalanced ? 'success' : 'danger'" dot>
                {{ $isBalanced ? __('Balanced') : __('Out of balance') }}
            </x-ui.badge>
        </div>
        <div class="card-body">
            <div class="flex gap-3" style="flex-wrap: wrap;">
                <div style="flex: 1 1 220px;">
                    <x-ui.stat-card icon="calculator" :label="__('Total debits')" :value="\App\Support\Formatter::money($totals['debit'])" />
                </div>
                <div style="flex: 1 1 220px;">
                    <x-ui.stat-card icon="calculator" :label="__('Total credits')" :value="\App\Support\Formatter::money($totals['credit'])" />
                </div>
                <div style="flex: 1 1 220px;">
                    <x-ui.stat-card icon="list" :label="__('Accounts with a balance')" :value="\App\Support\Formatter::number(count($rows))" />
                </div>
            </div>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Code') }}</th>
                    <th>{{ __('Account') }}</th>
                    <th class="num">{{ __('Debit') }}</th>
                    <th class="num">{{ __('Credit') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $index => $row)
                    @php
                        $type = $row['account']->type;
                        $isGroupHead = ! isset($rows[$index - 1]) || $rows[$index - 1]['account']->type !== $type;
                        $groupTotal = $groupTotals[$type] ?? null;
                        $isGroupTail = ! isset($rows[$index + 1]) || $rows[$index + 1]['account']->type !== $type;
                    @endphp

                    @if ($isGroupHead)
                        <tr style="background: var(--bg-surface-2);">
                            <td colspan="4" class="fw-semibold">{{ __(ucfirst($type)) }}</td>
                        </tr>
                    @endif

                    <tr>
                        <td class="font-mono">{{ $row['account']->code }}</td>
                        <td>{{ $row['account']->name }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($row['debit'], false) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($row['credit'], false) }}</td>
                    </tr>

                    @if ($isGroupTail && $groupTotal)
                        <tr style="font-weight: 600; background: var(--bg-surface-2);">
                            <td colspan="2" class="text-right">{{ __('Subtotal — :type', ['type' => __(ucfirst($type))]) }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::money($groupTotal['debit'], false) }}</td>
                            <td class="num financial">{{ \App\Support\Formatter::money($groupTotal['credit'], false) }}</td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="4">
                            <x-ui.empty-state icon="calculator" :title="__('The ledger is empty')"
                                            :text="__('No account carries a posted balance for the selected date.')" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if (count($rows))
                <tfoot>
                    <tr style="background: var(--bg-surface-3); font-weight: 700;">
                        <td colspan="2">{{ __('Grand total') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($totals['debit'], false) }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($totals['credit'], false) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</x-app-layout>
