<x-app-layout>
    <x-slot:title>{{ __('Balance sheet') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    <x-ui.page-header :title="__('Balance sheet')" :subtitle="__('Assets held against claims and capital at a point in time, from posted ledger activity.')"
                     :back="route('accounting.index')">
        <x-slot:actions>
            <x-ui.button variant="outline" icon="print" onclick="window.print()">{{ __('Print') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="card mb-4">
        <form method="GET" action="{{ route('accounting.balance-sheet') }}">
            <div class="card-body">
                <div class="field-bar">
                    <div style="flex: 0 1 220px;">
                        <x-ui.input name="as_of" type="date" id="as_of" :label="__('As of')" :value="$asOf" />
                    </div>
                    <div>
                        <x-ui.button type="submit" icon="filter">{{ __('Apply') }}</x-ui.button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    @unless ($isBalanced)
        <x-ui.alert type="danger" class="mb-4" :title="__('The balance sheet does not balance')">
            {{ __('Total assets (:assets) do not equal liabilities plus equity (:claims). The difference of :difference usually means a journal entry is missing or was altered outside the application.', [
                'assets' => \App\Support\Formatter::money($assetTotal),
                'claims' => \App\Support\Formatter::money($liabilitiesAndEquity),
                'difference' => \App\Support\Formatter::money(\App\Support\Money::sub($assetTotal, $liabilitiesAndEquity, 2)),
            ]) }}
        </x-ui.alert>
    @endunless

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 220px;">
            <x-ui.stat-card icon="briefcase" :label="__('Total assets')" :value="\App\Support\Formatter::money($assetTotal)" />
        </div>
        <div style="flex: 1 1 220px;">
            <x-ui.stat-card icon="file" :label="__('Total liabilities')" :value="\App\Support\Formatter::money($liabilityTotal)" />
        </div>
        <div style="flex: 1 1 220px;">
            <x-ui.stat-card icon="shield" :label="__('Total equity')" :value="\App\Support\Formatter::money($equityTotal)" />
        </div>
        <div style="flex: 1 1 220px;">
            <x-ui.badge :type="$isBalanced ? 'success' : 'danger'" dot>
                {{ $isBalanced ? __('Balanced') : __('Out of balance') }}
            </x-ui.badge>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">{{ __('Statement') }}</h2></div>
        <div class="card-body">
            <table class="data-table">
                <tbody>
                    <tr style="background: var(--bg-surface-2);"><td colspan="2" class="fw-semibold">{{ __('Assets') }}</td></tr>
                    @forelse ($assets as $row)
                        <tr>
                            <td>
                                <a href="{{ route('accounting.general-ledger', ['account' => $row['account']->id, 'to' => $asOf]) }}">
                                    {{ $row['account']->name }}
                                </a>
                                <span class="fs-xs text-muted font-mono">{{ $row['account']->code }}</span>
                            </td>
                            <td class="num financial">{{ \App\Support\Formatter::money($row['balance'], false) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-muted">{{ __('No asset balances.') }}</td></tr>
                    @endforelse
                    <tr style="font-weight: 700; background: var(--bg-surface-2);">
                        <td>{{ __('Total assets') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($assetTotal, false) }}</td>
                    </tr>

                    <tr style="background: var(--bg-surface-2);"><td colspan="2" class="fw-semibold">{{ __('Liabilities') }}</td></tr>
                    @forelse ($liabilities as $row)
                        <tr>
                            <td>
                                <a href="{{ route('accounting.general-ledger', ['account' => $row['account']->id, 'to' => $asOf]) }}">
                                    {{ $row['account']->name }}
                                </a>
                                <span class="fs-xs text-muted font-mono">{{ $row['account']->code }}</span>
                            </td>
                            <td class="num financial">{{ \App\Support\Formatter::money($row['balance'], false) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-muted">{{ __('No liability balances.') }}</td></tr>
                    @endforelse
                    <tr style="font-weight: 700; background: var(--bg-surface-2);">
                        <td>{{ __('Total liabilities') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($liabilityTotal, false) }}</td>
                    </tr>

                    <tr style="background: var(--bg-surface-2);"><td colspan="2" class="fw-semibold">{{ __('Equity') }}</td></tr>
                    @forelse ($equity as $row)
                        <tr>
                            <td>
                                <a href="{{ route('accounting.general-ledger', ['account' => $row['account']->id, 'to' => $asOf]) }}">
                                    {{ $row['account']->name }}
                                </a>
                                <span class="fs-xs text-muted font-mono">{{ $row['account']->code }}</span>
                            </td>
                            <td class="num financial">{{ \App\Support\Formatter::money($row['balance'], false) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-muted">{{ __('No equity balances.') }}</td></tr>
                    @endforelse

                    {{-- Current-period earnings are equity even though they have no
                         retained-earnings posting until the period is closed. --}}
                    <tr>
                        <td>{{ __('Retained earnings (derived)') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($retained, false) }}</td>
                    </tr>
                    <tr style="font-weight: 700; background: var(--bg-surface-2);">
                        <td>{{ __('Total liabilities and equity') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money($liabilitiesAndEquity, false) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr style="font-weight: 700; background: var(--bg-surface-3);">
                        <td>{{ __('Difference (assets − claims)') }}</td>
                        <td class="num financial">{{ \App\Support\Formatter::money(\App\Support\Money::sub($assetTotal, $liabilitiesAndEquity, 2), false) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</x-app-layout>
