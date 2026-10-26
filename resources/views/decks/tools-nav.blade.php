@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/deck-tools.css') }}">@endpush
<nav class="tools-nav" aria-label="Ferramentas de decks"><a href="{{ route('decks.index') }}">Meus decks</a><a href="{{ route('decks.history') }}">Histórico</a><a href="{{ route('collection.index') }}">Coleção</a><a href="{{ route('decks.compare') }}">Comparador</a></nav>
