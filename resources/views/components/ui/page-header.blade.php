@props(['title', 'subtitle' => null, 'back' => null])

<div class="page-header">
    <div class="flex-center gap-3">
        @if ($back)
            <a href="{{ $back }}" class="btn btn-ghost btn-icon" aria-label="{{ __('Back') }}">
                <x-ui.icon name="chevron-left" :size="18" />
            </a>
        @endif
        <div>
            <h1 class="page-header-title">{{ $title }}</h1>
            @if ($subtitle)<p class="page-header-subtitle">{{ $subtitle }}</p>@endif
        </div>
    </div>
    @if (isset($actions))
        <div class="page-header-actions">
            {{ $actions }}
        </div>
    @endif
</div>

