<x-guest-layout>
    <x-slot:title>{{ __('Reset password') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    <h1 class="auth-title">{{ __('Forgot password?') }}</h1>
    <p class="auth-subtitle">{{ __('Enter your account email and we will send you a reset link.') }}</p>

    @if ($errors->any())
        <x-ui.alert type="danger" class="mb-4">
            {{ $errors->first() }}
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <x-ui.input name="email" type="email" :label="__('Email address')" :placeholder="__('you@business.com')"
            required autofocus />

        <x-ui.button type="submit" block>{{ __('Send reset link') }}</x-ui.button>
    </form>

    <p class="mt-4 fs-sm text-center">
        <a href="{{ route('login') }}">{{ __('Back to sign in') }}</a>
    </p>
</x-guest-layout>
