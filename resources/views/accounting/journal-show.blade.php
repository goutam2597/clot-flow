<x-app-layout>
    <x-slot:title>{{ __('Entry :number', ['number' => $entry->entry_number]) }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="$entry->entry_number" :subtitle="$entry->description ?: __('Journal entry')"
                     :back="route('accounting.journals')">
        <x-slot:actions>
            <x-ui.button :href="route('accounting.index')" variant="outline" icon="calculator">{{ __('Accounting') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($entry->status === 'reversed')
        <x-ui.alert type="warning" class="mb-4">
            {{ __('This entry has been reversed. Its lines are offset by :number.', ['number' => $entry->reversedBy?->entry_number ?? '—']) }}
        </x-ui.alert>
    @endif

    <div class="flex gap-4 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 420px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Entry details') }}</h2></div>
                <div class="card-body">
                    <table class="data-table">
                        <tbody>
                            <tr>
                                <th style="width: 38%;">{{ __('Entry number') }}</th>
                                <td class="font-mono">{{ $entry->entry_number }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Date') }}</th>
                                <td>{{ \App\Support\Formatter::date($entry->entry_date) }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Description') }}</th>
                                <td>{{ $entry->description ?: '—' }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Source record') }}</th>
                                <td>
                                    @if ($entry->reference_type)
                                        {{ class_basename($entry->reference_type) }}
                                        <span class="text-muted font-mono">#{{ $entry->reference_id ?? '—' }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>{{ __('Branch') }}</th>
                                <td>{{ $entry->branch?->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Posted by') }}</th>
                                <td>{{ $entry->user?->name ?? __('System') }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Idempotency key') }}</th>
                                <td class="font-mono fs-xs">{{ $entry->idempotency_key ?: '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div style="flex: 1 1 300px;">
            <div class="card mb-4">
                <div class="card-header"><h2 class="card-title">{{ __('Balance check') }}</h2></div>
                <div class="card-body">
                    <div class="flex-between">
                        <span class="text-secondary">{{ __('Total debits') }}</span>
                        <span class="financial fw-semibold">{{ \App\Support\Formatter::money($entry->total_debit) }}</span>
                    </div>
                    <div class="flex-between mt-2">
                        <span class="text-secondary">{{ __('Total credits') }}</span>
                        <span class="financial fw-semibold">{{ \App\Support\Formatter::money($entry->total_credit) }}</span>
                    </div>
                    <div class="flex-between mt-3" style="padding-top: var(--space-3); border-top: 1px solid var(--border-color);">
                        <span class="fw-semibold">{{ __('Status') }}</span>
                        @if ($entry->isBalanced())
                            <x-ui.badge type="success" dot>{{ __('Balanced') }}</x-ui.badge>
                        @else
                            <x-ui.badge type="danger">{{ __('Out of balance') }}</x-ui.badge>
                        @endif
                    </div>
                </div>
            </div>

            @can('accounting.manage')
                @if ($entry->status === 'posted')
                    <x-ui.button variant="danger" icon="refresh" block data-modal-open="#reverse-entry">
                        {{ __('Reverse this entry') }}
                    </x-ui.button>
                @endif
            @endcan
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Account') }}</th>
                    <th>{{ __('Description') }}</th>
                    <th class="num">{{ __('Debit') }}</th>
                    <th class="num">{{ __('Credit') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entry->lines as $line)
                    <tr>
                        <td>
                            <a href="{{ route('accounting.general-ledger', ['account' => $line->account_id]) }}" class="fw-medium">
                                {{ $line->account?->name ?? __('Unknown account') }}
                            </a>
                            <div class="fs-xs text-muted font-mono">{{ $line->account?->code }}</div>
                        </td>
                        <td class="fs-sm">{{ $line->description ?: '—' }}</td>
                        <td class="num financial">{{ $line->debit != 0 ? \App\Support\Formatter::money($line->debit, false) : '' }}</td>
                        <td class="num financial">{{ $line->credit != 0 ? \App\Support\Formatter::money($line->credit, false) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">{{ __('This entry has no lines.') }}</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr style="background: var(--bg-surface-2); font-weight: 600;">
                    <td colspan="2">{{ __('Totals') }}</td>
                    <td class="num financial">{{ \App\Support\Formatter::money($entry->total_debit, false) }}</td>
                    <td class="num financial">{{ \App\Support\Formatter::money($entry->total_credit, false) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @can('accounting.manage')
        <x-ui.modal id="reverse-entry" :title="__('Reverse entry :number', ['number' => $entry->entry_number])">
            <x-ui.alert type="warning">
                {{ __('Posted entries are never edited or deleted. Reversing posts an offsetting entry that cancels this one, leaving both in the ledger for audit.') }}
            </x-ui.alert>
            <form method="POST" action="{{ route('accounting.reverse', $entry) }}" class="mt-4" data-prevent-double>
                @csrf
                <div class="form-group mb-0">
                    <label class="form-label" for="reverse-reason">{{ __('Reason for reversal') }}<span class="required">*</span></label>
                    <textarea class="form-control" id="reverse-reason" name="reason" rows="3" required
                              placeholder="{{ __('e.g. Duplicate sale entry posted in error') }}"></textarea>
                    <div class="form-hint">{{ __('Stored on the reversing entry and in the audit trail.') }}</div>
                </div>
                <div class="flex gap-3 mt-4">
                    <x-ui.button type="submit" variant="danger" icon="refresh">{{ __('Post reversal') }}</x-ui.button>
                    <x-ui.button variant="outline" data-modal-close>{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endcan
</x-app-layout>
