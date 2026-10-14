@php
    $user = auth()->user();
@endphp

<header class="topbar">
    <button type="button" class="topbar-toggle" data-sidebar-toggle aria-label="{{ __('Toggle sidebar') }}">
        <x-ui.icon name="menu" :size="20" />
    </button>

    <h1 class="topbar-page-title">{{ $header ?? '' }}</h1>

    <div class="topbar-spacer"></div>

    {{-- Language, theme and account. Shared with the till header, which has no
         topbar of its own. --}}
    @include('partials.account-controls', ['compact' => false])
</header>
