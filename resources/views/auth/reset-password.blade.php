<x-guest-layout>
    <x-slot:title>{{ __('Set new password') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    <h1 class="auth-title">{{ __('Set a new password') }}</h1>
    <p class="auth-subtitle">{{ __('Choose a strong password for your account.') }}</p>

    @if ($errors->any())
        <x-ui.alert type="danger" class="mb-4">
            {{ $errors->first() }}
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ $email }}">

        <x-ui.input name="password" type="password" :label="__('New password')" required autofocus />
        <x-ui.input name="password_confirmation" type="password" :label="__('Confirm new password')" required />

        <x-ui.button type="submit" block>{{ __('Reset password') }}</x-ui.button>
    </form>

    <p class="mt-4 fs-sm text-center">
        <a href="{{ route('login') }}">{{ __('Back to sign in') }}</a>
    </p>
</x-guest-layout>
