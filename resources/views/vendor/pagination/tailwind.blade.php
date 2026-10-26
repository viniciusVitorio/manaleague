{{-- Paginação no estilo do projeto (substitui a view Tailwind padrão do Laravel, que depende de classes que o app não carrega). --}}
@if ($paginator->hasPages())
    <nav class="ui-pagination" role="navigation" aria-label="Paginação">
        @if ($paginator->onFirstPage())
            <span class="ui-btn ui-btn-outline ui-btn-sm is-disabled" aria-disabled="true">&laquo; Anterior</span>
        @else
            <a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">&laquo; Anterior</a>
        @endif

        <span class="ui-pagination-pages">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="ui-pagination-gap" aria-disabled="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="ui-btn ui-btn-primary ui-btn-sm" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="ui-btn ui-btn-ghost ui-btn-sm" href="{{ $url }}" aria-label="Ir para a página {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </span>

        @if ($paginator->hasMorePages())
            <a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Próxima &raquo;</a>
        @else
            <span class="ui-btn ui-btn-outline ui-btn-sm is-disabled" aria-disabled="true">Próxima &raquo;</span>
        @endif
    </nav>
@endif
