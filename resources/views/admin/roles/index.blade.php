<x-app-layout>
    <x-slot:title>{{ __('Roles & permissions') }} Â· {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Roles & permissions')" :subtitle="__('A role bundles the permissions a person needs. Grant the smallest set that lets them do their job.')">
        <x-slot:actions>
            <x-ui.button :href="route('admin.users.index')" variant="outline" icon="users">{{ __('Users') }}</x-ui.button>
            <x-ui.button :href="route('admin.roles.create')" icon="plus">{{ __('New role') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex gap-3 mb-4" style="flex-wrap: wrap;">
        <div style="flex: 1 1 190px;"><x-ui.stat-card icon="shield" :label="__('Roles')" :value="\App\Support\Formatter::number($stats['roles'])" /></div>
        <div style="flex: 1 1 190px;"><x-ui.stat-card icon="key" :label="__('Permissions')" :value="\App\Support\Formatter::number($stats['permissions'])" /></div>
        <div style="flex: 1 1 190px;"><x-ui.stat-card icon="users" :label="__('Roles in use')" :value="\App\Support\Formatter::number($stats['assigned'])" /></div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('Role') }}</th>
                    <th>{{ __('Permissions') }}</th>
                    <th>{{ __('Assigned users') }}</th>
                    <th class="cell-actions">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($roles as $role)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $role->name }}</div>
                            <div class="fs-xs text-muted font-mono">{{ $role->guard_name }}</div>
                        </td>
                        <td>
                            @if ($role->permissions_count > 0)
                                <x-ui.badge type="primary">{{ trans_choice('{1} :count permission|[2,*] :count permissions', $role->permissions_count, ['count' => $role->permissions_count]) }}</x-ui.badge>
                            @else
                                <x-ui.badge type="warning">{{ __('No permissions') }}</x-ui.badge>
                            @endif
                        </td>
                        <td>
                            @if ($role->users->isEmpty())
                                <span class="text-muted">{{ __('Unassigned') }}</span>
                            @else
                                <div class="flex-center gap-2" style="flex-wrap: wrap;">
                                    @foreach ($role->users->take(4) as $assigned)
                                        <x-ui.badge>{{ $assigned->name }}</x-ui.badge>
                                    @endforeach
                                    @if ($role->users->count() > 4)
                                        <span class="fs-xs text-muted">+{{ $role->users->count() - 4 }}</span>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="cell-actions">
                            <div class="flex-center gap-2" style="justify-content: flex-end;">
                                <x-ui.button :href="route('admin.roles.edit', $role)" variant="ghost" size="sm" icon="edit" class="table-actions-btn"
                                             :aria-label="__('Edit :name', ['name' => $role->name])" />

                                @if (! $role->users()->exists() && ! auth()->user()->hasRole($role->name))
                                    <x-ui.delete-form :action="route('admin.roles.destroy', $role)"
                                                                 :label="__('Delete :name', ['name' => $role->name])"
                                                                 :confirm="__('Delete the :name role? This cannot be undone.', ['name' => $role->name])" />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <x-ui.empty-state icon="shield" :title="__('No roles yet')" :text="__('Create a role to group the permissions each job needs.')">
                                <x-ui.button :href="route('admin.roles.create')" icon="plus">{{ __('New role') }}</x-ui.button>
                            </x-ui.empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {!! $roles->links('partials.pagination') !!}
</x-app-layout>
