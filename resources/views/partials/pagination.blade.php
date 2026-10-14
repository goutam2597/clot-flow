@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator|\Illuminate\Pagination\Paginator $paginator */
    $paginator = $paginator ?? null;
@endphp

@if ($paginator && $paginator->hasPages())
    <nav class="pagination-wrap" aria-label="{{ __('Pagination') }}">
        <p class="pagination-summary text-muted">
            @if ($paginator instanceof \Illuminate\Pagination\LengthAwarePaginator)
                {{ __('Showing :from to :to of :total entries', [
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'total' => $paginator->total(),
                ]) }}
            @else
                {{ __('Page :current of :last', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}
            @endif
        </p>

        <ul class="pagination">
            {{-- Previous --}}
            <li>
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" aria-label="{{ __('Previous') }}">&lsaquo;</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('Previous') }}">&lsaquo;</a>
                @endif
            </li>

            {{-- Page numbers --}}
            @foreach ($elements as $page => $element)
                @if (is_string($element))
                    <li><span aria-disabled="true">{{ $element }}</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="active"><span aria-current="page">{{ $page }}</span></li>
                        @else
                            <li><a href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next --}}
            <li>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('Next') }}">&rsaquo;</a>
                @else
                    <span aria-disabled="true" aria-label="{{ __('Next') }}">&rsaquo;</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
