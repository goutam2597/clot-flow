<x-app-layout>
    <x-slot:title>{{ __('Journal entries') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Journal entries')" :subtitle="__('Each entry is balanced at the moment it is posted. Corrections are made by reversing an entry, never by editing it.')" :back="route('accounting.index')" />

    <div class="card mb-4">
        <form method="GET" action="{{ route('accounting.journals') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 260px;">
                        <x-ui.input name="search" :label="__('Search')" :value="$filters['search'] ?? ''"
                                    :placeholder="__('Entry number or description')" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.select name="status" :label="__('Status')" :options="['posted' => __('Posted'), 'reversed' => __('Reversed')]"
                                     :value="$filters['status'] ?? ''" :placeholder="__('Any status')" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.input name="from" type="date" :label="__('From')" :value="$filters['from'] ?? ''" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.input name="to" type="date" :label="__('Until')" :value="$filters['to'] ?? ''" />
                    </div>
                </div>
            </div>
            <div class="card-footer flex-center gap-3">
                <x-ui.button type="submit" icon="search">{{ __('Apply filters') }}</x-ui.button>
                <x-ui.button :href="route('accounting.journals')" variant="ghost">{{ __('Reset') }}</x-ui.button>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Entry') }}</th>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Description') }}</th>
                    <th>{{ __('Source') }}</th>
                    <th class="num">{{ __('Debit') }}</th>
                    <th class="num">{{ __('Credit') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="cell-actions"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td class="fw-semibold font-mono">
                            <a href="{{ route('accounting.journals.show', $entry) }}">{{ $entry->entry_number }}</a>
                        </td>
                        <td class="text-nowrap">{{ \App\Support\Formatter::date($entry->entry_date) }}</td>
                        <td>
                            <div>{{ $entry->description ?: '—' }}</div>
                            <div class="fs-xs text-muted">{{ $entry->lines->count() }} {{ __('lines') }}</div>
                        </td>
                        <td class="fs-sm">
                            <div>{{ class_basename((string) $entry->reference_type) ?: '—' }}</div>
                            <div class="fs-xs text-muted">{{ $entry->branch?->name ?? '—' }}</div>
                        </td>
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
                            <x-ui.empty-state icon="receipt" :title="__('No journal entries match these filters')"
                                            :text="__('Entries are written automatically when a sale, purchase or expense is recorded.')" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {!! $entries->links('partials.pagination') !!}
</x-app-layout>
