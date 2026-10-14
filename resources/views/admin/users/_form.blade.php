{{--
    Shared user create / edit form.

    Expects:
      $user        App\Models\User  (a blank model when creating)
      $branches    key => label
      $roles       Spatie role models
      $locales     code => label
      $themes      key => label
      $statuses    key => label
      $mode        'create' | 'edit'
--}}
@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $currentRole = old('role', $user->roles->first()?->name ?? ($roles->first()?->name ?? ''));
@endphp

<form method="POST"
      action="{{ $isEdit ? route('admin.users.update', $user) : route('admin.users.store') }}"
      data-prevent-double>
    @csrf
    @if ($isEdit) @method('PATCH') @endif

    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Account details') }}</h2>
        </div>
        <div class="card-body">
            <div class="flex gap-3" style="flex-wrap: wrap;">
                <div style="flex: 1 1 260px;">
                    <x-ui.input name="name" :label="__('Full name')" :value="$user->name" required autofocus />
                </div>
                <div style="flex: 1 1 260px;">
                    <x-ui.input name="email" type="email" :label="__('Email address')" :value="$user->email" required autocomplete="off" />
                </div>
                <div style="flex: 1 1 200px;">
                    <x-ui.input name="phone" type="tel" :label="__('Phone')" :value="$user->phone" />
                </div>
                <div style="flex: 1 1 220px;">
                    <x-ui.select name="branch_id" :label="__('Home branch')" :options="$branches" :value="$user->branch_id"
                                 :placeholder="__('No branch assigned')"
                                 :hint="__('Determines which branch records this user can reach by default.')" />
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Access') }}</h2>
        </div>
        <div class="card-body">
            <div class="flex gap-3" style="flex-wrap: wrap;">
                <div style="flex: 1 1 220px;">
                    <x-ui.select name="role" :label="__('Role')" required
                                 :options="$roles->pluck('name', 'name')" :value="$currentRole" />
                </div>
                <div style="flex: 1 1 180px;">
                    <x-ui.select name="status" :label="__('Status')" required
                                 :options="$statuses" :value="old('status', $user->status ?? 'active')" />
                </div>
                <div style="flex: 1 1 180px;">
                    <x-ui.select name="locale" :label="__('Language')"
                                 :options="$locales" :value="old('locale', $user->locale)" :placeholder="__('Follow system default')" />
                </div>
                <div style="flex: 1 1 160px;">
                    <x-ui.select name="theme" :label="__('Theme')" required
                                 :options="$themes" :value="old('theme', $user->theme ?? 'light')" />
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">{{ __('Password') }}</h2>
        </div>
        <div class="card-body">
            <div class="flex gap-3" style="flex-wrap: wrap;">
                <div style="flex: 1 1 240px;">
                    <x-ui.input name="password" type="password" :label="__('Password')"
                                :required="$isEdit ? false : true"
                                autocomplete="new-password"
                                :hint="$isEdit ? __('Leave blank to keep the current password.') : __('At least 8 characters.')" />
                </div>
                <div style="flex: 1 1 240px;">
                    <x-ui.input name="password_confirmation" type="password" :label="__('Confirm password')"
                                :required="$isEdit ? false : true" autocomplete="new-password" />
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <x-ui.button type="submit" icon="check" data-prevent-double>{{ $isEdit ? __('Save changes') : __('Create user') }}</x-ui.button>
        <x-ui.button :href="route('admin.users.index')" variant="outline">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>
