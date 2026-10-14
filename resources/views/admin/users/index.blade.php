<x-app-layout>
    <x-slot:title>{{ __('Users') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Users')" :subtitle="__('Accounts that sign in, own transactions and appear on the audit trail.')">
        <x-slot:actions>
            <x-ui.button :href="route('admin.roles.index')" variant="outline" icon="shield">{{ __('Roles') }}</x-ui.button>
            @can('users.manage')
                <x-ui.button :href="route('admin.users.create')" icon="plus">{{ __('New user') }}</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Summary --}}
    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 190px;"><x-ui.stat-card icon="users" :label="__('Total users')" :value="\App\Support\Formatter::number($stats['total'])" /></div>
        <div style="flex: 1 1 190px;"><x-ui.stat-card icon="check-circle" :label="__('Active')" :value="\App\Support\Formatter::number($stats['active'])" /></div>
        <div style="flex: 1 1 190px;"><x-ui.stat-card icon="alert" :label="__('Restricted')" :value="\App\Support\Formatter::number($stats['restricted'])" /></div>
        <div style="flex: 1 1 190px;"><x-ui.stat-card icon="shield" :label="__('Roles defined')" :value="\App\Support\Formatter::number($stats['roles'])" /></div>
    </div>

    {{-- Filters --}}
    <div class="card mb-4">
        <form method="GET" action="{{ route('admin.users.index') }}">
            <div class="card-body">
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    <div style="flex: 2 1 260px;">
                        <x-ui.input name="q" :label="__('Search')" :value="$filters['q']" :placeholder="__('Name or email address')" />
                    </div>
                    <div style="flex: 1 1 170px;">
                        <x-ui.select name="role" :label="__('Role')" :options="$roles" :value="$filters['role']" :placeholder="__('Any role')" />
                    </div>
                    <div style="flex: 1 1 170px;">
                        <x-ui.select name="branch_id" :label="__('Branch')" :options="$branches" :value="$filters['branch_id']" :placeholder="__('Any branch')" />
                    </div>
                    <div style="flex: 1 1 150px;">
                        <x-ui.select name="status" :label="__('Status')" :options="$statuses" :value="$filters['status']" :placeholder="__('Any status')" />
                    </div>
                </div>
            </div>
            <div class="card-footer flex-center gap-3">
                <x-ui.button type="submit" icon="search">{{ __('Apply filters') }}</x-ui.button>
                <x-ui.button :href="route('admin.users.index')" variant="ghost">{{ __('Reset') }}</x-ui.button>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('User') }}</th>
                    <th>{{ __('Role') }}</th>
                    <th>{{ __('Branch') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Last login') }}</th>
                    <th class="cell-actions">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    @php $roleName = $user->roles->first()?->name; @endphp
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $user->name }}</div>
                            <div class="fs-xs text-muted">{{ $user->email }}</div>
                            @if ($user->phone)
                                <div class="fs-xs text-muted">{{ $user->phone }}</div>
                            @endif
                        </td>
                        <td>
                            @if ($roleName)
                                <x-ui.badge type="primary">{{ $roleName }}</x-ui.badge>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $user->branch?->name ?? '—' }}</td>
                        <td>
                            @php
                                $statusType = match ($user->status) {
                                    'active' => 'success',
                                    'suspended' => 'danger',
                                    default => 'default',
                                };
                            @endphp
                            <x-ui.badge :type="$statusType" dot>{{ $statuses[$user->status] ?? $user->status }}</x-ui.badge>
                        </td>
                        <td class="text-nowrap">{{ $user->last_login_at ? \App\Support\Formatter::dateTime($user->last_login_at) : '—' }}</td>
                        <td class="cell-actions">
                            <div class="flex-center gap-2" style="justify-content: flex-end;">
                                @can('users.manage')
                                    <x-ui.button :href="route('admin.users.edit', $user)" variant="ghost" size="sm" icon="edit" class="table-actions-btn"
                                                 :aria-label="__('Edit :name', ['name' => $user->name])" />

                                    @if ($user->id !== auth()->id())
                                        <x-ui.button type="button" variant="ghost" size="sm" icon="refresh" class="table-actions-btn"
                                                     data-modal-open="#user-status" data-status-trigger
                                                     data-action="{{ route('admin.users.status', $user) }}"
                                                     data-name="{{ $user->name }}"
                                                     :aria-label="__('Change status of :name', ['name' => $user->name])" />
                                    @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-ui.empty-state icon="users" :title="__('No users match these filters')" :text="__('Try a different search term, or clear the filters.')">
                                @can('users.manage')
                                    <x-ui.button :href="route('admin.users.create')" icon="plus">{{ __('Create the first user') }}</x-ui.button>
                                @endcan
                            </x-ui.empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {!! $users->links('partials.pagination') !!}

    {{-- One shared dialog; the row action rewrites the form target and name. --}}
    <x-ui.modal id="user-status" :title="__('Change account status')">
        <x-ui.alert type="info">{{ __('A status change applies on the user’s next request. Financial records already posted are never altered — deactivate rather than delete an account that owns transactions.') }}</x-ui.alert>
        <p class="fs-sm text-muted mt-3 mb-0" data-status-target></p>

        <form method="POST" data-status-form class="mt-3">
            @csrf
            <div class="flex gap-3" style="flex-wrap: wrap;">
                <x-ui.button type="submit" name="status" value="active" variant="outline" icon="check" style="flex: 1 1 140px;">{{ __('Activate') }}</x-ui.button>
                <x-ui.button type="submit" name="status" value="suspended" variant="outline" icon="lock" style="flex: 1 1 140px;">{{ __('Suspend') }}</x-ui.button>
                <x-ui.button type="submit" name="status" value="inactive" variant="outline" icon="x" style="flex: 1 1 140px;">{{ __('Deactivate') }}</x-ui.button>
            </div>
        </form>

        <x-slot:footer>
            <x-ui.button variant="ghost" data-modal-close>{{ __('Cancel') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @push('scripts')
        <script>
            (function () {
                var form = document.querySelector('[data-status-form]');
                var target = document.querySelector('[data-status-target]');
                if (!form) { return; }

                document.querySelectorAll('[data-status-trigger]').forEach(function (trigger) {
                    trigger.addEventListener('click', function () {
                        form.setAttribute('action', trigger.dataset.action);
                        target.textContent = trigger.dataset.name;
                    });
                });
            })();
        </script>
    @endpush
</x-app-layout>
