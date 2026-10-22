@if ($paginator->hasPages())
<nav class="career-pagination" aria-label="Páginas das carreiras">
    @if ($paginator->onFirstPage())
        <span class="career-page-link" aria-disabled="true">Anterior</span>
    @else
        <a class="career-page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a>
    @endif

    @foreach ($elements as $element)
        @if (is_string($element))
            <span class="career-page-ellipsis" aria-hidden="true">{{ $element }}</span>
        @endif
        @if (is_array($element))
            @foreach ($element as $page => $url)
                @if ($page === $paginator->currentPage())
                    <span class="career-page-link" aria-current="page">{{ $page }}</span>
                @else
                    <a class="career-page-link" href="{{ $url }}" aria-label="Ir para a página {{ $page }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach

    @if ($paginator->hasMorePages())
        <a class="career-page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Próxima</a>
    @else
        <span class="career-page-link" aria-disabled="true">Próxima</span>
    @endif
</nav>
@endif
