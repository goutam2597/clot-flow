<x-app-layout>
    <x-slot:title>{{ __('Profile') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    @include('partials.flash')

    <x-ui.page-header :title="__('Profile')" :subtitle="__('Your name, contact details and password. Role and branch are managed by an administrator.')" />

    <div class="flex gap-4" style="flex-wrap: wrap; align-items: flex-start;">
        {{-- Identity --}}
        <div style="flex: 1 1 320px;">
            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Account') }}</h2></div>
                <div class="card-body">
                    <div class="flex-center gap-3 mb-4">
                        <span class="avatar avatar-lg">{{ $user->initials }}</span>
                        <div>
                            <div class="fw-semibold">{{ $user->name }}</div>
                            <div class="fs-sm text-muted">{{ $user->email }}</div>
                        </div>
                    </div>

                    <table class="data-table">
                        <tbody>
                            <tr>
                                <th style="width: 40%;">{{ __('Role') }}</th>
                                <td>
                                    @if ($user->roles->first())
                                        <x-ui.badge type="primary">{{ $user->roles->first()->name }}</x-ui.badge>
                                    @else
                                        <span class="text-muted">{{ __('No role assigned') }}</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>{{ __('Branch') }}</th>
                                <td>{{ $user->branch?->name ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Status') }}</th>
                                <td>{{ __(ucfirst($user->status ?? 'active')) }}</td>
                            </tr>
                            <tr>
                                <th>{{ __('Last login') }}</th>
                                <td>{{ $user->last_login_at ? \App\Support\Formatter::dateTime($user->last_login_at) : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Editable details --}}
        <div style="flex: 2 1 420px;">
            <div class="card mb-4">
                <div class="card-header"><h2 class="card-title">{{ __('Contact details') }}</h2></div>
                <form method="POST" action="{{ route('profile.update') }}" data-prevent-double>
                    @csrf
                    @method('PATCH')
                    <div class="card-body">
                        <div class="flex gap-3" style="flex-wrap: wrap;">
                            <div style="flex: 1 1 240px;">
                                <x-ui.input name="name" :label="__('Full name')" :value="$user->name" required />
                            </div>
                            <div style="flex: 1 1 240px;">
                                <x-ui.input name="email" type="email" :label="__('Email address')" :value="$user->email" required autocomplete="off" />
                            </div>
                            <div style="flex: 1 1 200px;">
                                <x-ui.input name="phone" type="tel" :label="__('Phone')" :value="$user->phone" />
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <x-ui.button type="submit" icon="check">{{ __('Save profile') }}</x-ui.button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('Change password') }}</h2></div>
                <form method="POST" action="{{ route('profile.password') }}" data-prevent-double>
                    @csrf
                    @method('PUT')
                    <div class="card-body">
                        <div class="flex gap-3" style="flex-wrap: wrap;">
                            <div style="flex: 1 1 100%;">
                                <x-ui.input name="current_password" type="password" :label="__('Current password')" required autocomplete="current-password" />
                            </div>
                            <div style="flex: 1 1 240px;">
                                <x-ui.input name="password" type="password" :label="__('New password')" required autocomplete="new-password" />
                            </div>
                            <div style="flex: 1 1 240px;">
                                <x-ui.input name="password_confirmation" type="password" :label="__('Confirm new password')" required autocomplete="new-password" />
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <x-ui.button type="submit" variant="outline" icon="lock">{{ __('Update password') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
