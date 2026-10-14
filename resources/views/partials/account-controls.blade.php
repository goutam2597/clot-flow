@php
    /**
     * Language, theme and account.
     *
     * Shared by the topbar and the till header. The till has no topbar, so
     * without this the only way to sign out of a shift is to navigate away from
     * the till and into the admin - which on a till is a dozen keystrokes and
     * abandons whatever basket was on screen.
     *
     * Sized by the `compact` flag rather than a second set of markup: the two
     * callers differ only in height, and two copies of a menu is two places for
     * the sign-out link to go missing.
     */
    $user = auth()->user();
    $activeLanguages = \App\Models\Language::where('is_active', true)->orderBy('sort_order')->get();
    $controlClass = $compact ? 'pos-icon-btn' : 'topbar-toggle';
@endphp

@if ($activeLanguages->count() > 1)
    <div class="dropdown">
        <button type="button" class="{{ $controlClass }}" data-dropdown-toggle
                aria-expanded="false" aria-label="{{ __('Change language') }}">
            <x-ui.icon name="globe" :size="$compact ? 16 : 18" />
        </button>

        <div class="dropdown-menu" style="min-width: 160px;">
            @foreach ($activeLanguages as $lang)
                <form method="POST" action="{{ route('locale.switch', $lang->code) }}">
                    @csrf
                    <button type="submit" class="dropdown-item {{ app()->getLocale() === $lang->code ? 'fw-semibold' : '' }}">
                        {{ $lang->native_name ?: $lang->name }}
                    </button>
                </form>
            @endforeach
        </div>
    </div>
@endif

<button type="button" class="{{ $controlClass }}" data-theme-toggle
        aria-label="{{ __('Toggle theme') }}">
    <x-ui.icon name="moon" :size="$compact ? 16 : 18" />
</button>

<div class="dropdown">
    {{-- No chevron: beside two icon buttons this already reads as a header
         control, and a third glyph would compete with the avatar. --}}
    <button type="button" class="pos-account {{ $compact ? 'is-compact' : '' }}"
            data-dropdown-toggle aria-expanded="false" aria-label="{{ __('Account') }}">
        <span class="avatar avatar-sm">{{ $user->initials }}</span>
        <span class="pos-account-name hide-sm fw-medium fs-sm">{{ $user->name }}</span>
    </button>

    <div class="dropdown-menu" style="min-width: 200px;">
        <div style="padding:var(--space-2) var(--space-3);">
            <div class="fw-semibold fs-sm">{{ $user->name }}</div>
            <div class="text-muted fs-xs">{{ $user->email }}</div>
            @if ($user->roles->count())
                <span class="badge badge-primary mt-2">{{ $user->roles->first()->name }}</span>
            @endif
        </div>

        <div class="dropdown-divider"></div>

        <a href="{{ route('profile.edit') }}" class="dropdown-item">
            <x-ui.icon name="user" :size="15" /> {{ __('Profile') }}
        </a>

        <div class="dropdown-divider"></div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="dropdown-item danger">
                <x-ui.icon name="logout" :size="15" /> {{ __('Log Out') }}
            </button>
        </form>
    </div>
</div>
