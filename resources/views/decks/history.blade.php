@extends('layouts.app')
@section('content')
@include('decks.tools-nav')
<header class="page-head">
    <div><h1>Histórico de decks</h1><p>Versões salvas dos seus decks, inclusive de decks excluídos. Elas não mudam mais e só você pode ver.</p></div>
</header>

@if($versions->isEmpty())
    <div class="ui-empty">
        <span class="ui-empty-icon"><x-icon name="clock" :size="18" /></span>
        <div><p class="ui-empty-title">Seu histórico começa aqui</p><p class="ui-empty-text">Salve uma versão na página de um deck. Participações em eventos preservam automaticamente o estado usado na largada.</p></div>
        <a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ route('decks.index') }}">Meus decks</a>
    </div>
@else
    {{-- Uma linha por versão: capa, nome e motivo à esquerda; tamanho, formato e data à direita. A linha inteira abre a versão. --}}
    <div class="card deckver-list">
        @foreach($versions as $version)
            @php($versionCards = collect($version->payload['cards'] ?? [])->sum('quantity'))
            <a class="deckver-row" href="{{ route('decks.versions.show', $version) }}">
                <span class="deckver-art" @if($version->payload['cover_art'] ?? null) style="--art: url('{{ $version->payload['cover_art'] }}')" @endif aria-hidden="true"></span>
                <span class="deckver-main">
                    <strong>{{ $version->payload['name'] }}</strong>
                    <small>Versão {{ $version->number }} · {{ $version->label }}</small>
                </span>
                <span class="deckver-meta">
                    <span>{{ $versionCards }} {{ $versionCards === 1 ? 'carta' : 'cartas' }} · {{ ($version->payload['format'] ?? null) ?: 'Livre' }}</span>
                    <time datetime="{{ $version->created_at->toIso8601String() }}">{{ $version->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</time>
                </span>
                <x-icon name="chevron-right" class="deckver-go" />
            </a>
        @endforeach
    </div>
    {{ $versions->links() }}
@endif
@endsection
