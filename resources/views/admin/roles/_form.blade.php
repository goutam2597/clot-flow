{{--
    Shared role create / edit form.

    Expects:
      $role              Spatie\Permission\Models\Role
      $permissionGroups  module label => [['name' => ..., 'label' => ...], ...]
      $selected          list of permission names currently granted
      $mode              'create' | 'edit'
--}}
@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $allPermissions = collect($permissionGroups)->flatten(1)->pluck('name');
@endphp

<form method="POST"
      action="{{ $isEdit ? route('admin.roles.update', $role) : route('admin.roles.store') }}"
      data-prevent-double>
    @csrf
    @if ($isEdit) @method('PATCH') @endif

    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Role details') }}</h2>
        </div>
        <div class="card-body">
            <div class="flex gap-3" style="flex-wrap: wrap;">
                <div style="flex: 1 1 300px;">
                    <x-ui.input name="name" :label="__('Role name')" :value="$role->name" required autofocus
                                placeholder="e.g. Shift Supervisor" />
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header flex-between">
            <h2 class="card-title">{{ __('Permissions') }}</h2>
            <div class="flex-center gap-2">
                <x-ui.button type="button" variant="ghost" size="sm" icon="check" data-permissions="all">{{ __('Select all') }}</x-ui.button>
                <x-ui.button type="button" variant="ghost" size="sm" icon="x" data-permissions="none">{{ __('Clear') }}</x-ui.button>
            </div>
        </div>

        <div class="card-body">
            <x-ui.alert type="info" class="mb-4">{{ __('Permissions are enforced on the server. Hiding a button is never the only protection — every action re-checks the permission before it runs.') }}</x-ui.alert>

            <div class="flex gap-4" style="flex-wrap: wrap;">
                @forelse ($permissionGroups as $module => $permissions)
                    <fieldset class="card permission-group" style="flex: 1 1 300px;">
                        <legend class="card-title fs-sm">{{ $module }}</legend>
                        <div class="card-body">
                            @foreach ($permissions as $permission)
                                <x-ui.checkbox name="permissions[]"
                                               value="{{ $permission['name'] }}"
                                               data-permission-checkbox
                                               :label="$permission['label']"
                                               :checked="in_array($permission['name'], $selected, true)" />
                            @endforeach
                        </div>
                    </fieldset>
                @empty
                    <x-ui.empty-state icon="shield" :title="__('No permissions are registered')" :text="__('Run the role and permission seeder to create the default permission set.')" />
                @endforelse
            </div>
        </div>

        <div class="card-footer">
            <p class="fs-sm text-muted mb-0">
                <span data-permission-count>{{ count($selected) }}</span>
                {{ trans_choice('{1} permission selected|[2,*] permissions selected', count($selected)) }}
                {{ __('of :total', ['total' => $allPermissions->count()]) }}
            </p>
        </div>
    </div>

    <div class="form-actions">
        <x-ui.button type="submit" icon="check">{{ $isEdit ? __('Save changes') : __('Create role') }}</x-ui.button>
        <x-ui.button :href="route('admin.roles.index')" variant="outline">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>

@push('scripts')
    <script>
        (function () {
            var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-permission-checkbox]'));
            var counter = document.querySelector('[data-permission-count]');
            if (!boxes.length) { return; }

            function refresh() {
                if (counter) { counter.textContent = boxes.filter(function (b) { return b.checked; }).length; }
            }
            boxes.forEach(function (box) { box.addEventListener('change', refresh); });

            document.querySelectorAll('[data-permissions]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var on = button.dataset.permissions === 'all';
                    boxes.forEach(function (box) { box.checked = on; });
                    refresh();
                });
            });

            refresh();
        })();
    </script>
@endpush
