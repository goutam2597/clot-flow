@php
    $actionMeta = [
        'synced' => ['type' => 'success', 'label' => __('Synchronized')],
        'sync_failed' => ['type' => 'danger', 'label' => __('Failed')],
        'sync_attention' => ['type' => 'warning', 'label' => __('Needs attention')],
        'sync_marked_synced' => ['type' => 'info', 'label' => __('Confirmed manually')],
        'sync_push' => ['type' => 'primary', 'label' => __('Batch pushed')],
    ];

    $actionOptions = collect($actions)->mapWithKeys(
        fn ($a) => [$a => $actionMeta[$a]['label'] ?? $a]
    )->all();
@endphp

<x-app-layout>
    <x-slot:title>{{ __('Sync log') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Sync log')" :subtitle="__('Every push attempt, its outcome, and the idempotency ledger that keeps retries safe.')"
                     :back="route('sync.status')" />

    <div class="card mb-4">
        <form method="GET" action="{{ route('sync.log') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 260px;">
                        <x-ui.input name="q" :label="__('Search')" :value="$filters['q']"
                                    :placeholder="__('Invoice number, sale id or description')" />
                    </div>
                    <div style="flex: 1 1 200px;">
                        <x-ui.select name="action" :label="__('Action')" :options="$actionOptions"
                                     :value="$filters['action']" :placeholder="__('Any action')" />
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
                <x-ui.button type="submit" icon="search">{{ __('Apply filters') }}</x-ui.button>
                <x-ui.button :href="route('sync.log')" variant="ghost">{{ __('Reset') }}</x-ui.button>
            </div>
        </form>
    </div>

    <div class="flex gap-4 mb-4" style="flex-wrap: wrap; align-items: flex-start;">
        {{-- Activity trail --}}
        <div style="flex: 2 1 460px;">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ __('When') }}</th>
                            <th>{{ __('Action') }}</th>
                            <th>{{ __('Detail') }}</th>
                            <th>{{ __('User') }}</th>
                            <th class="cell-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entries as $entry)
                            @php $meta = $actionMeta[$entry->action] ?? ['type' => 'default', 'label' => $entry->action]; @endphp
                            <tr>
                                <td class="text-nowrap">
                                    <div>{{ \App\Support\Formatter::dateTime($entry->created_at) }}</div>
                                    <div class="fs-xs text-muted">{{ \Carbon\Carbon::parse($entry->created_at)->diffForHumans() }}</div>
                                </td>
                                <td><x-ui.badge :type="$meta['type']">{{ $meta['label'] }}</x-ui.badge></td>
                                <td>
                                    <div class="fs-sm">{{ $entry->description ?: '—' }}</div>
                                    @if (is_array($entry->new_values) && ! empty($entry->new_values))
                                        <details class="fs-xs text-muted">
                                            <summary style="cursor: pointer;">{{ __('Show details') }}</summary>
                                            <pre class="mt-2" style="white-space: pre-wrap; word-break: break-word;">{{ json_encode($entry->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                        </details>
                                    @endif
                                </td>
                                <td class="fs-sm">{{ $entry->user?->name ?? __('System') }}</td>
                                <td class="cell-actions">
                                    @if ($entry->entity_id)
                                        <x-ui.button :href="route('sales.show', $entry->entity_id)" variant="ghost" size="sm" icon="eye" class="table-actions-btn"
                                                     :aria-label="__('Open the sale this entry refers to')" />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-ui.empty-state icon="sync" :title="__('No sync activity matches these filters')"
                                                    :text="__('Push attempts are recorded here with their outcome.')" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {!! $entries->links('partials.pagination') !!}
        </div>

        {{-- Idempotency ledger --}}
        <div style="flex: 1 1 340px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Idempotency ledger') }}</h2></div>
                <div class="card-body">
                    <p class="fs-sm text-muted">
                        {{ __('Each sale holds one stable key. A retried push returns the original sale instead of creating a duplicate.') }}
                    </p>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>{{ __('Sale') }}</th>
                                <th>{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($keys as $key)
                                <tr>
                                    <td>
                                        <div class="font-mono fs-xs">{{ \Illuminate\Support\Str::limit($key->key, 20) }}</div>
                                        <div class="fs-xs text-muted">{{ \App\Models\Sale::find($key->resource_id)?->invoice_number ?? '#' . $key->resource_id }}</div>
                                    </td>
                                    <td>
                                        <x-ui.badge :type="$key->status === 'completed' ? 'success' : 'warning'">{{ $key->status }}</x-ui.badge>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-muted">{{ __('No keys recorded yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {!! $keys->links('partials.pagination') !!}
            </div>
        </div>
    </div>
</x-app-layout>
