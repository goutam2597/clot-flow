@php
    $statusMeta = [
        'synced' => ['type' => 'success', 'icon' => 'check-circle', 'label' => __('Synchronized')],
        'pending' => ['type' => 'warning', 'icon' => 'clock', 'label' => __('Pending')],
        'syncing' => ['type' => 'info', 'icon' => 'refresh', 'label' => __('Syncing')],
        'failed' => ['type' => 'danger', 'icon' => 'alert', 'label' => __('Failed')],
        'attention' => ['type' => 'warning', 'icon' => 'info', 'label' => __('Needs attention')],
    ];

    $statusOptions = array_merge(
        ['queue' => __('Queued (needs work)'), 'all' => __('All records')],
        collect($statusMeta)->map(fn ($meta) => $meta['label'])->all()
    );

    $sourceOptions = [
        'offline' => __('Offline terminals'),
        'online' => __('Online'),
        'all' => __('All sources'),
    ];

    // Scope echoed into every push form so a batch action only touches the
    // records the operator is currently looking at.
    $scopeInputs = [
        'sync_status' => $filters['sync_status'],
        'source' => $filters['source'],
        'q' => $filters['q'],
        'status' => $filters['status'],
        'branch_id' => $filters['branch_id'],
        'from' => $filters['from'],
        'to' => $filters['to'],
        'sort' => $filters['sort'],
        'dir' => $filters['dir'],
    ];

    $sortLink = function (string $column) use ($filters) {
        $dir = ($filters['sort'] === $column && $filters['dir'] === 'desc') ? 'asc' : 'desc';

        return request()->fullUrlWithQuery(['sort' => $column, 'dir' => $dir]);
    };
@endphp

<x-app-layout>
    <x-slot:title>{{ __('Offline Sync') }}</x-slot:title>
    <x-slot:header>{{ __('Offline Synchronization') }}</x-slot:header>

    <x-ui.page-header
        :title="__('Offline Synchronization')"
        :subtitle="__('Sales captured on disconnected terminals, reconciled with head office.')">
        <x-slot:actions>
            <x-ui.button :href="route('sync.log')" variant="outline" size="sm" icon="file">
                {{ __('Sync log') }}
            </x-ui.button>

            @if ($canRetry)
                <form method="POST" action="{{ route('sync.push') }}">
                    @csrf
                    <input type="hidden" name="action" value="retry">
                    @foreach ($scopeInputs as $name => $value)
                        @if ($value !== '')<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
                    @endforeach
                    <x-ui.button type="submit" variant="primary" size="sm" icon="refresh">
                        {{ __('Retry queue') }}
                    </x-ui.button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @include('partials.flash')

    @if ($health === 'attention')
        <x-ui.alert type="danger" :title="__('Sync queue needs attention')" :dismissible="true">
            {{ __(':failed sale(s) failed validation and :attention sale(s) need a manual decision. Review the queue below before the next shift close.', ['failed' => $counts['failed'], 'attention' => $counts['attention']]) }}
        </x-ui.alert>
    @elseif ($health === 'pending')
        <x-ui.alert type="warning" :title="__('Sales waiting to synchronize')" :dismissible="true">
            {{ trans_choice(':count sale(s) are queued for head office. Oldest record: :oldest.', $counts['queue'], ['oldest' => $oldestQueuedAt ? $oldestQueuedAt->diffForHumans() : __('—')]) }}
        </x-ui.alert>
    @else
        <x-ui.alert type="success" :title="__('Queue is clear')" :dismissible="true">
            {{ __('Every offline sale in scope has been synchronized.') }}
        </x-ui.alert>
    @endif

    {{-- __STATS__ --}}
    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 170px; min-width: 0;">
            <x-ui.stat-card
                :label="__('Synchronized')"
                :value="$counts['synced']"
                icon="check-circle"
                :hint="$statusMeta['synced']['label']" />
        </div>
        <div style="flex: 1 1 170px; min-width: 0;">
            <x-ui.stat-card :label="__('Pending')" :value="$counts['pending']" icon="clock" />
        </div>
        <div style="flex: 1 1 170px; min-width: 0;">
            <x-ui.stat-card :label="__('Syncing')" :value="$counts['syncing']" icon="refresh" />
        </div>
        <div style="flex: 1 1 170px; min-width: 0;">
            <x-ui.stat-card
                :label="__('Failed')"
                :value="$counts['failed']"
                icon="alert"
                :hint="\App\Support\Formatter::money($failedValue)" />
        </div>
        <div style="flex: 1 1 170px; min-width: 0;">
            <x-ui.stat-card :label="__('Needs attention')" :value="$counts['attention']" icon="info" />
        </div>
        <div style="flex: 1 1 190px; min-width: 0;">
            <x-ui.stat-card
                :label="__('Queued value')"
                :value="\App\Support\Formatter::money($queueValue)"
                icon="money"
                :hint="__('Last push :time', ['time' => $lastSyncedAt ? $lastSyncedAt->diffForHumans() : __('never')])" />
        </div>
    </div>

    <form method="GET" action="{{ route('sync.status') }}" class="filter-bar">
        <x-ui.input name="q" :value="$filters['q']" :placeholder="__('Invoice, customer, key')" />

        <x-ui.select name="sync_status" :value="$filters['sync_status']" :options="$statusOptions" />

        <x-ui.select name="source" :value="$filters['source']" :options="$sourceOptions" />

        <x-ui.select
            name="status"
            :value="$filters['status']"
            :placeholder="__('Any sale status')"
            :options="array_combine($saleStatuses, array_map('ucfirst', $saleStatuses))" />

        @if ($branches->count() > 1)
            <x-ui.select
                name="branch_id"
                :value="$filters['branch_id']"
                :placeholder="__('All branches')"
                :options="$branches"
                option-value="id"
                option-label="name" />
        @endif

        <x-ui.input name="from" type="date" :value="$filters['from']" />
        <x-ui.input name="to" type="date" :value="$filters['to']" />

        <div class="spacer"></div>

        <x-ui.button type="submit" variant="secondary" size="sm" icon="filter">
            {{ __('Apply') }}
        </x-ui.button>

        @if (collect($filters)->only(['q', 'from', 'to', 'branch_id'])->filter()->isNotEmpty()
            || $filters['sync_status'] !== 'queue'
            || $filters['source'] !== 'offline'
            || $filters['status'] !== '')
            <x-ui.button :href="route('sync.status')" variant="ghost" size="sm">
                {{ __('Reset') }}
            </x-ui.button>
        @endif
    </form>

    @if ($sales->count())
        <div class="table-wrap">
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr>
                            <th>
                                <a href="{{ $sortLink('invoice_number') }}" class="{{ $filters['sort'] === 'invoice_number' ? 'fw-semibold' : '' }}">
                                    {{ __('Invoice') }}
                                </a>
                            </th>
                            <th>
                                <a href="{{ $sortLink('sold_at') }}" class="{{ $filters['sort'] === 'sold_at' ? 'fw-semibold' : '' }}">
                                    {{ __('Sold at') }}
                                </a>
                            </th>
                            <th>{{ __('Terminal / branch') }}</th>
                            <th>{{ __('Source') }}</th>
                            <th>{{ __('Sync status') }}</th>
                            <th>{{ __('Sale status') }}</th>
                            <th class="text-center">{{ __('Lines') }}</th>
                            <th class="num">
                                <a href="{{ $sortLink('grand_total') }}" class="{{ $filters['sort'] === 'grand_total' ? 'fw-semibold' : '' }}">
                                    {{ __('Grand total') }}
                                </a>
                            </th>
                            <th class="actions">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sales as $sale)
                            @php $meta = $statusMeta[$sale->sync_status] ?? $statusMeta['attention']; @endphp
                            <tr>
                                <td>
                                    <span class="fw-semibold font-mono">{{ $sale->invoice_number }}</span>
                                    @if ($sale->customer)
                                        <div class="text-muted fs-xs">{{ $sale->customer->name }}</div>
                                    @endif
                                    @if ($sale->idempotency_key)
                                        <div class="text-muted fs-xs font-mono">{{ \Illuminate\Support\Str::limit($sale->idempotency_key, 13, '…') }}</div>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    {{ \App\Support\Formatter::dateTime($sale->sold_at) }}
                                    <div class="text-muted fs-xs">{{ $sale->sold_at?->diffForHumans() }}</div>
                                </td>
                                <td>
                                    {{ $sale->branch?->name ?? '—' }}
                                    <div class="text-muted fs-xs">{{ $sale->user?->name ?? '—' }}</div>
                                </td>
                                <td>
                                    <x-ui.badge :type="$sale->source === 'offline' ? 'warning' : 'default'">
                                        {{ ucfirst($sale->source) }}
                                    </x-ui.badge>
                                </td>
                                <td>
                                    <x-ui.badge :type="$meta['type']" :dot="true">{{ $meta['label'] }}</x-ui.badge>
                                </td>
                                <td>
                                    <x-ui.badge :type="$sale->status === 'completed' ? 'default' : 'danger'">
                                        {{ ucfirst($sale->status) }}
                                    </x-ui.badge>
                                </td>
                                <td class="text-center">{{ $sale->items_count }}</td>
                                <td class="num fw-semibold">{{ \App\Support\Formatter::money($sale->grand_total) }}</td>
                                <td class="actions">
                                    <div class="flex-center gap-2" style="justify-content: flex-end;">
                                        @if ($sale->sync_status !== 'synced' && $canRetry)
                                            <form method="POST" action="{{ route('sync.push') }}">
                                                @csrf
                                                <input type="hidden" name="action" value="retry">
                                                <input type="hidden" name="sale_id" value="{{ $sale->id }}">
                                                <x-ui.button type="submit" variant="outline" size="sm" icon="refresh">
                                                    {{ __('Retry') }}
                                                </x-ui.button>
                                            </form>
                                        @endif

                                        @if ($sale->sync_status !== 'synced' && $canMarkSynced)
                                            <form method="POST" action="{{ route('sync.push') }}">
                                                @csrf
                                                <input type="hidden" name="action" value="mark_synced">
                                                <input type="hidden" name="sale_id" value="{{ $sale->id }}">
                                                <x-ui.button type="submit" variant="success" size="sm" icon="check-circle">
                                                    {{ __('Mark synced') }}
                                                </x-ui.button>
                                            </form>
                                        @endif

                                        @if ($sale->sync_status === 'synced')
                                            <span class="text-muted fs-xs">{{ __('Up to date') }}</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{ $sales->links('partials.pagination') }}
    @else
        <div class="card">
            <x-ui.empty-state
                icon="sync"
                :title="__('Nothing waiting in the sync queue')"
                :text="__('No sale matches the current filters. Adjust the filters, or check the sync log for recent activity.')">
                @if ($canRetry || $canMarkSynced)
                    <x-ui.button :href="route('sync.status')" variant="outline" size="sm">
                        {{ __('Clear filters') }}
                    </x-ui.button>
                @endif
            </x-ui.empty-state>
        </div>
    @endif


    @if ($stalledCount > 0)
        <x-ui.alert type="warning" :dismissible="true">
            {{ trans_choice(':count queued sale(s) are older than two hours — the terminal may have gone offline again before finishing the push.', $stalledCount, ['count' => $stalledCount]) }}
        </x-ui.alert>
    @endif

    <p class="text-muted fs-xs mt-3">
        {{ __('A queue push processes up to :limit records per batch; every sale is validated and reconciled on its own before it is marked synchronized.', ['limit' => 200]) }}
    </p>

</x-app-layout>
