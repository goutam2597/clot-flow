<x-app-layout>
    <x-slot:title>{{ __('Returns & refunds') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @php
        $statusOptions = collect($statuses)->mapWithKeys(fn ($s) => [$s => __(ucfirst($s))])->all();
        $perPageOptions = collect($perPageOptions)->mapWithKeys(fn ($v) => [$v => (string) $v])->all();
    @endphp

    @include('partials.flash')

    <x-ui.page-header :title="__('Returns & refunds')" :subtitle="__('Each return restocks the warehouse, posts an offsetting journal entry and keeps the original sale intact.')">
        <x-slot:actions>
            <x-ui.button :href="route('sales.index')" variant="outline" icon="sales">{{ __('All sales') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="refresh" :label="__('Returns')" :value="\App\Support\Formatter::number($summary['count'])"
                            :hint="\App\Support\Formatter::money($summary['grand_total'])" />
        </div>
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="money" :label="__('Refunded')" :value="\App\Support\Formatter::money($summary['refunded_total'])" />
        </div>
        <div style="flex: 1 1 210px;">
            <x-ui.stat-card icon="box" :label="__('Restocked')" :value="\App\Support\Formatter::number($summary['restocked'])" />
        </div>
    </div>

    <div class="card mb-4">
        <form method="GET" action="{{ route('sales.returns') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 250px;">
                        <x-ui.input name="search" :label="__('Search')" :value="$filters['search'] ?? ''"
                                    :placeholder="__('Return number, invoice or customer')" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.select name="status" :label="__('Status')" :options="$statusOptions" :value="$filters['status'] ?? ''"
                                     :placeholder="__('Any status')" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.input name="from" type="date" :label="__('From')" :value="$filters['from'] ?? ''" />
                    </div>
                    <div style="flex: 1 1 160px;">
                        <x-ui.input name="to" type="date" :label="__('Until')" :value="$filters['to'] ?? ''" />
                    </div>
                    <div style="flex: 0 1 130px;">
                        <x-ui.select name="per_page" :label="__('Per page')" :options="$perPageOptions" :value="$filters['per_page'] ?? 20" />
                    </div>
                </div>
            </div>
            <div class="card-footer flex-center gap-3">
                <x-ui.button type="submit" icon="search">{{ __('Apply filters') }}</x-ui.button>
                <x-ui.button :href="route('sales.returns')" variant="ghost">{{ __('Reset') }}</x-ui.button>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Return') }}</th>
                    <th>{{ __('Against invoice') }}</th>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Customer') }}</th>
                    <th class="num">{{ __('Value') }}</th>
                    <th class="num">{{ __('Refunded') }}</th>
                    <th>{{ __('Restocked') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="cell-actions"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($returns as $return)
                    <tr>
                        <td class="fw-semibold font-mono">
                            <a href="{{ route('sales.returns.show', $return) }}">{{ $return->return_number }}</a>
                            @if ($return->reason)
                                <div class="fs-xs text-muted">{{ $return->reason }}</div>
                            @endif
                        </td>
                        <td class="font-mono fs-sm">
                            <a href="{{ route('sales.show', $return->sale) }}">{{ $return->sale?->invoice_number ?? '—' }}</a>
                        </td>
                        <td class="text-nowrap">{{ \App\Support\Formatter::dateTime($return->returned_at) }}</td>
                        <td>
                            @if ($return->customer)
                                {{ $return->customer->name }}
                            @else
                                <span class="text-muted">{{ __('Walk-in customer') }}</span>
                            @endif
                        </td>
                        <td class="num financial">{{ \App\Support\Formatter::money($return->grand_total) }}</td>
                        <td class="num financial fw-semibold">{{ \App\Support\Formatter::money($return->refunded_total) }}</td>
                        <td>
                            @if ($return->restock)
                                <x-ui.badge type="success">{{ __('Yes') }}</x-ui.badge>
                            @else
                                <x-ui.badge>{{ __('No') }}</x-ui.badge>
                            @endif
                        </td>
                        <td>
                            <x-ui.badge :type="$return->status === 'completed' ? 'success' : 'warning'">{{ __(ucfirst($return->status)) }}</x-ui.badge>
                        </td>
                        <td class="cell-actions">
                            <x-ui.button :href="route('sales.returns.show', $return)" variant="ghost" size="sm" icon="eye" class="table-actions-btn"
                                         :aria-label="__('View :number', ['number' => $return->return_number])" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <x-ui.empty-state icon="refresh" :title="__('No returns match these filters')"
                                            :text="__('Returns are raised from a completed sale.')" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {!! $returns->links('partials.pagination') !!}
</x-app-layout>
