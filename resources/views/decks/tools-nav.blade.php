@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/deck-tools.css') }}">@endpush
{{-- Abas das ferramentas de decks: a página atual fica marcada. --}}
<nav class="tools-nav" aria-label="Ferramentas de decks">
    @foreach([
        ['decks.index', 'Meus decks', 'layers', ['decks.index']],
        ['collection.index', 'Coleção', 'library', ['collection.*']],
        ['decks.history', 'Histórico', 'clock', ['decks.history', 'decks.versions.*']],
        ['decks.compare', 'Comparar', 'repeat', ['decks.compare']],
    ] as [$toolRoute, $toolLabel, $toolIcon, $toolMatch])
        <a href="{{ route($toolRoute) }}" @if(request()->routeIs(...$toolMatch)) aria-current="page" @endif><x-icon :name="$toolIcon" />{{ $toolLabel }}</a>
    @endforeach
</nav>
