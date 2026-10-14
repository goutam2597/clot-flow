<!DOCTYPE html>
@php
    $activeLanguage = \App\Models\Language::where('code', app()->getLocale())->first()
        ?? \App\Models\Language::defaultLanguage();
    $documentDirection = (session('direction') ?? ($activeLanguage?->direction ?? 'ltr')) === 'rtl' ? 'rtl' : 'ltr';
    $businessName = \App\Support\Formatter::setting('legal_name', config('app.name', 'Meridian POS'));
@endphp
<html lang="{{ str_replace('_', '-', $activeLanguage?->locale ?? app()->getLocale()) }}"
      dir="{{ $documentDirection }}"
      data-theme="{{ session('theme', 'light') }}">
<head>
    @include('partials.head', [
        'title' => $title ?? null,
        'extraStyles' => ['css/auth.css'],
    ])
</head>
<body class="auth-body">
    <div class="auth-shell">
        {{-- The form owns the start edge; the logo sits with it, not on the
             hero panel, so a narrow window never loses the identity. --}}
        <main class="auth-panel">
            <div class="auth-form">
                <div class="auth-brandline">
                    @include('partials.brand-logo', ['variant' => 'auth'])
                </div>

                @if (session('status'))
                    <x-ui.alert type="success" class="mb-4">{{ session('status') }}</x-ui.alert>
                @endif

                {{ $slot }}

                <p class="auth-footer">
                    &copy; {{ date('Y') }} {{ config('app.name', 'Meridian POS') }}. {{ __('All rights reserved.') }}
                </p>
            </div>
        </main>

        {{-- Brand hero: full-height dark panel — statement, a thermal
             receipt that never stops printing, and the capability list. --}}
        <aside class="auth-hero">
            <div class="auth-hero-head">
                <p class="auth-hero-eyebrow">{{ __('Retail · Inventory · Accounts') }}</p>
                <h2 class="auth-hero-quote">{{ __('One system for the whole business') }}</h2>
                <p class="auth-hero-sub">{{ __('Sell at the counter, track stock in every warehouse, and keep a ledger that balances — without running three programs side by side.') }}</p>
            </div>

            {{-- Till mockup: the product itself as the hero art. Tiles ring
                 up in sequence, the receipt feed prints, the total breathes,
                 the pay button shines. Pure CSS, no invented data. --}}
            <div class="auth-device" aria-hidden="true">
                <div class="auth-device-bar">
                    <span class="auth-device-dot"></span>
                    <span class="auth-device-dot"></span>
                    <span class="auth-device-dot"></span>
                    <span class="auth-device-title"></span>
                </div>

                <div class="auth-device-screen">
                    <div class="auth-device-grid">
                        @for ($i = 0; $i < 8; $i++)
                            <span class="auth-device-tile"></span>
                        @endfor
                    </div>

                    <div class="auth-device-feed">
                        <div class="auth-receipt-feed">
                            @for ($i = 0; $i < 3; $i++)
                                <div class="auth-receipt-set">
                                    <span class="auth-rl auth-rl-title"></span>
                                    <span class="auth-rl-row"><span class="auth-rl auth-rl-w50"></span><span class="auth-rl auth-rl-w12"></span></span>
                                    <span class="auth-rl-row"><span class="auth-rl auth-rl-w40"></span><span class="auth-rl auth-rl-w14"></span></span>
                                    <span class="auth-rl-row"><span class="auth-rl auth-rl-w45"></span><span class="auth-rl auth-rl-w10"></span></span>
                                    <span class="auth-rl auth-rl-divider"></span>
                                    <span class="auth-rl auth-rl-total"></span>
                                    <span class="auth-rl auth-rl-foot"></span>
                                </div>
                            @endfor
                        </div>
                    </div>
                </div>

                <div class="auth-device-foot">
                    <span class="auth-device-total"><span class="auth-device-total-fill"></span></span>
                    <span class="auth-device-pay"></span>
                </div>
            </div>

            {{-- Floating system-status chips. Glassy, gently bobbing,
                statuses only — no invented numbers. --}}
            <div class="auth-chip auth-chip-a" aria-hidden="true">
                <span class="auth-chip-dot"></span>{{ __('POS online') }}
            </div>
            <div class="auth-chip auth-chip-b" aria-hidden="true">
                <span class="auth-chip-dot auth-chip-dot-quiet"></span>{{ __('Ledger balanced') }}
            </div>

            <ul class="auth-hero-points">
                <li>{{ __('Point of sale that keeps selling through a dropped connection') }}</li>
                <li>{{ __('Live stock, purchases and transfers across every branch') }}</li>
                <li>{{ __('Invoices, tax and returns that reconcile to the ledger') }}</li>
                <li>{{ __('Roles, permissions and an audit trail on every change') }}</li>
            </ul>
        </aside>
    </div>

    <div class="toast-container" id="toast-container"></div>

    <script src="{{ \App\Support\Asset::url('js/app.js') }}"></script>
    <script src="{{ \App\Support\Asset::url('js/bulk.js') }}"></script>
    <script src="{{ \App\Support\Asset::url('js/select.js') }}"></script>
    @stack('scripts')
</body>
</html>
