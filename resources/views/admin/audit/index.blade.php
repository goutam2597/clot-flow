<x-app-layout>
    <x-slot:title>{{ __('Audit logs') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Audit logs')" :subtitle="__('An append-only record of who changed what, and when. Entries are written by the services themselves and are never edited here.')" />

    @if ($branchScope)
        <x-ui.alert type="info" class="mb-4">
            {{ __('You are viewing the audit trail for your own branch only.') }}
        </x-ui.alert>
    @endif

    <div class="card mb-4">
        <form method="GET" action="{{ route('admin.audit.index') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 260px;">
                        <x-ui.input name="search" :label="__('Search')" :value="$filters['search'] ?? ''"
                                    :placeholder="__('Description, record type or user name')" />
                    </div>
                    <div style="flex: 1 1 180px;">
                        <x-ui.select name="action" :label="__('Action')"
                                     :options="collect($actions)->mapWithKeys(fn ($a) => [$a => $a])->all()"
                                     :value="$filters['action'] ?? ''" :placeholder="__('Any action')" />
                    </div>
                    @unless ($branchScope)
                        <div style="flex: 1 1 180px;">
                            <x-ui.select name="branch_id" :label="__('Branch')" :options="$branches" :value="$filters['branch_id'] ?? ''"
                                         :placeholder="__('All branches')" />
                        </div>
                    @endunless
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
                <x-ui.button :href="route('admin.audit.index')" variant="ghost">{{ __('Reset') }}</x-ui.button>
                <span class="spacer"></span>
                <span class="fs-sm text-muted">{{ __('Showing :count entries', ['count' => \App\Support\Formatter::number($logs->count())]) }}</span>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('When') }}</th>
                    <th>{{ __('User') }}</th>
                    <th>{{ __('Action') }}</th>
                    <th>{{ __('Record') }}</th>
                    <th>{{ __('Change') }}</th>
                    <th>{{ __('Branch') }}</th>
                    <th>{{ __('Source') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    @php
                        $old = is_array($log->old_values) ? $log->old_values : [];
                        $new = is_array($log->new_values) ? $log->new_values : [];
                        $changed = array_keys($new);
                        $shortType = class_basename((string) $log->entity_type);
                    @endphp
                    <tr>
                        <td class="text-nowrap">
                            <div>{{ \App\Support\Formatter::dateTime($log->created_at) }}</div>
                            @if ($log->transaction_id)
                                <div class="fs-xs text-muted font-mono">{{ $log->transaction_id }}</div>
                            @endif
                        </td>
                        <td>
                            <div>{{ $log->user?->name ?? __('System') }}</div>
                            @if ($log->user)
                                <div class="fs-xs text-muted">{{ $log->user->email }}</div>
                            @endif
                        </td>
                        <td><x-ui.badge type="info">{{ $log->action }}</x-ui.badge></td>
                        <td>
                            @if ($log->entity_type)
                                <div class="fw-medium">{{ $shortType }}</div>
                                <div class="fs-xs text-muted font-mono">#{{ $log->entity_id ?? '—' }}</div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td style="max-width: 340px;">
                            <div class="fs-sm">{{ $log->description ?: '—' }}</div>
                            @if ($changed)
                                <details class="fs-xs text-muted">
                                    <summary style="cursor: pointer;">{{ __('Show values') }}</summary>
                                    <div class="mt-2">
                                        @foreach (array_slice($changed, 0, 12) as $key)
                                            <div class="font-mono">
                                                <span class="text-muted">{{ $key }}:</span>
                                                @if (array_key_exists($key, $old))
                                                    <span class="text-danger">{{ \Illuminate\Support\Str::limit(is_scalar($old[$key]) ? (string) $old[$key] : json_encode($old[$key]), 40) }}</span>
                                                    <span aria-hidden="true">&rarr;</span>
                                                @endif
                                                <span class="text-success">{{ \Illuminate\Support\Str::limit(is_scalar($new[$key]) ? (string) $new[$key] : json_encode($new[$key]), 40) }}</span>
                                            </div>
                                        @endforeach
                                        @if (count($changed) > 12)
                                            <div class="text-muted">+{{ count($changed) - 12 }} {{ __('more') }}</div>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        </td>
                        <td>{{ $log->branch?->name ?? '—' }}</td>
                        <td class="fs-xs text-muted font-mono">
                            {{ $log->ip_address ?: '—' }}
                            @if ($log->user_agent)
                                <div class="text-truncate" style="max-width: 180px;" title="{{ $log->user_agent }}">{{ $log->user_agent }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-ui.empty-state icon="shield" :title="__('No audit entries match these filters')"
                                            :text="__('Audit entries are written whenever a record is created, changed or reversed.')" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {!! $logs->links('partials.pagination') !!}
</x-app-layout>
