<x-guest-layout>
    <x-slot:title>{{ __('Sign in') }} · {{ config('app.name', 'Meridian POS') }}</x-slot:title>

    <p class="auth-eyebrow">{{ __('Sign in') }}</p>
    <h1 class="auth-title">{{ __('Welcome back') }}</h1>
    <p class="auth-subtitle">{{ __('Sign in to your business account.') }}</p>

    @if ($errors->any())
        <x-ui.alert type="danger" class="mb-4">
            {{ $errors->first() }}
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <x-ui.input name="email" type="email" :label="__('Email address')" :placeholder="__('you@business.com')"
            autocomplete="username" required autofocus />

        {{-- Written by hand rather than through x-ui.input: the label row
             shares its line with "Forgot password?", and the show/hide
             toggle has to sit inside the field — neither fits the
             component's plain <input>. Classes match the component's output
             so the error state renders identically. --}}
        <div class="form-group">
            <div class="auth-label-row">
                <label class="form-label" for="password">
                    {{ __('Password') }}<span class="required">*</span>
                </label>
                <a href="{{ route('password.request') }}" class="auth-minor-link">{{ __('Forgot password?') }}</a>
            </div>

            <div class="auth-password">
                <input type="password" name="password" id="password" class="form-control"
                    placeholder="{{ __('Your password') }}"
                    autocomplete="current-password" required
                    data-password-input @if ($errors->has('password')) aria-invalid="true"
                    aria-describedby="password-error" @endif>

                <button type="button" class="auth-password-toggle" data-password-toggle
                    aria-label="{{ __('Show password') }}" aria-pressed="false"
                    aria-controls="password">
                    <x-ui.icon name="eye" :size="16" class="auth-eye-open" />
                    <x-ui.icon name="eye-off" :size="16" class="auth-eye-closed" />
                </button>
            </div>

            <p class="auth-caps-hint" data-caps-hint hidden>
                {{ __('Caps Lock is on.') }}
            </p>

            @error('password')
                <div class="form-error" id="password-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="auth-remember">
            {{-- The component carries its own label: wrapping it in another
                 label nests labels and prints the text twice. --}}
            <x-ui.checkbox name="remember" :label="__('Keep me signed in')" />
        </div>

        <x-ui.button type="submit" block iconRight="arrow-right">{{ __('Sign in') }}</x-ui.button>
    </form>

    @push('scripts')
        <script>
            (function () {
                var input = document.querySelector('[data-password-input]');
                var toggle = document.querySelector('[data-password-toggle]');
                var capsHint = document.querySelector('[data-caps-hint]');

                // Show/hide password. The eye icons are stacked in CSS; this
                // class decides which one shows.
                toggle.addEventListener('click', function () {
                    var showing = input.type === 'text';
                    input.type = showing ? 'password' : 'text';
                    toggle.classList.toggle('is-showing', !showing);
                    toggle.setAttribute('aria-pressed', String(!showing));
                    toggle.setAttribute('aria-label',
                        showing ? @json(__('Show password')) : @json(__('Hide password')));
                    input.focus();
                });

                // Cashiers type with Caps Lock on more than anyone. Whisper
                // it while it's on, on both key events, without ever stealing
                // focus or scroll for it.
                function updateCapsHint(event) {
                    var on = event.getModifierState && event.getModifierState('CapsLock');
                    if (capsHint) { capsHint.hidden = !on; }
                }

                input.addEventListener('keydown', updateCapsHint);
                input.addEventListener('keyup', updateCapsHint);
                input.addEventListener('blur', function () {
                    if (capsHint) { capsHint.hidden = true; }
                });
            })();
        </script>
    @endpush
</x-guest-layout>
