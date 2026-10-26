@if ($paginator->hasPages())
    <nav class="ui-pagination" role="navigation" aria-label="Paginação">
        @if ($paginator->onFirstPage())
            <span class="ui-btn ui-btn-outline ui-btn-sm is-disabled" aria-disabled="true">&laquo; Anterior</span>
        @else
            <a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">&laquo; Anterior</a>
        @endif

        @if ($paginator->hasMorePages())
            <a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Próxima &raquo;</a>
        @else
            <span class="ui-btn ui-btn-outline ui-btn-sm is-disabled" aria-disabled="true">Próxima &raquo;</span>
        @endif
    </nav>
@endif
