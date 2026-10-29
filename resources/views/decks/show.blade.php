@extends('layouts.app')
@push('head')<script src="{{ \App\Support\VersionedAsset::url('js/deck-builder.js') }}" defer></script>@endpush
@section('content')
@php
    $boardLabel = ['main' => 'Deck', 'side' => 'Sideboard', 'commander' => 'Comandante'];
@endphp
<header class="deck-hero card" @if($summary['cover']) style="--cover: url('{{ $summary['cover'] }}')" @endif>
    <div class="deck-hero-inner">
        <a class="back-link" href="{{ $isOwner ? route('decks.index') : route('profiles.show', $deck->profile) }}">← {{ $isOwner ? 'Meus decks' : $deck->profile->nickname }}</a>
        <div class="title-line"><span class="format-badge">{{ $deck->format ?: 'Formato livre' }}</span>@if($deck->is_primary)<span class="status status-finished">★ Principal</span>@endif @include('decks.partials.single-set-badge')</div>
        <h1>{{ $deck->name }}</h1>
        <div class="deck-hero-meta">
            @include('partials.color-pips', ['colors' => $deck->colors])
            <span><b>{{ $summary['main'] }}</b> cartas</span>
            @if($summary['side'])<span><b>{{ $summary['side'] }}</b> no side</span>@endif
            <span><b>{{ $summary['lands'] }}</b> terrenos</span>
            <span>CMC médio <b>{{ number_format($summary['avg_cmc'], 2, ',', '') }}</b></span>
            @unless($isOwner)<span>por <b><x-profile-link :of="$deck->profile">{{ $deck->profile->nickname }}</x-profile-link></b></span>@endunless
        </div>
    </div>
    @if($isOwner)
    <div class="deck-edit">
        @if($summary['cover_options']->isNotEmpty())<button type="button" class="btn btn-ghost btn-small" data-toggle="cover-picker" aria-controls="cover-picker" aria-expanded="false">🖼 Trocar capa</button>@endif
        <a class="btn btn-ghost btn-small" href="{{ route('decks.edit', $deck) }}">Editar deck</a>
    </div>
    @endif
</header>

@if($isOwner && $summary['cover_options']->isNotEmpty())
<section class="card section-card cover-picker" id="cover-picker" hidden>
    <div class="section-heading"><div><span class="eyebrow">Banner</span><h2>Escolha a carta da capa</h2></div>
        <button type="button" class="btn btn-ghost btn-small" data-toggle="cover-picker" aria-controls="cover-picker">Fechar</button></div>
    <form method="POST" action="{{ route('decks.cover', $deck) }}" class="cover-grid">@csrf @method('PATCH')
        <button class="cover-option cover-auto @if(! $summary['cover_card_id']) is-selected @endif" name="card_id" value="" aria-pressed="{{ $summary['cover_card_id'] ? 'false' : 'true' }}">
            <span class="cover-auto-icon">✨</span><span class="cover-name">Automática</span>
        </button>
        @foreach($summary['cover_options'] as $option)
            @php($selected = $summary['cover_card_id'] === $option->id)
            <button class="cover-option @if($selected) is-selected @endif" name="card_id" value="{{ $option->id }}" aria-pressed="{{ $selected ? 'true' : 'false' }}" title="{{ $option->name }}">
                <img src="{{ $option->art_url }}" alt="" loading="lazy" width="200" height="146">
                <span class="cover-name">{{ $option->faceNames()[0] ?? $option->name }}</span>
            </button>
        @endforeach
    </form>
</section>
@endif

@if($summary['warnings'])
<div class="alert alert-warning"><strong>Construção:</strong><ul>@foreach($summary['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>
@endif

<div class="deck-layout">
<div>
    @if($summary['commanders']->isNotEmpty())
    <section class="card section-card commander-zone">
        <span class="eyebrow">Comandante</span>
        <div class="commander-cards">
        @foreach($summary['commanders'] as $card)
            <figure class="commander-card">
                @if($card->image_url)<img src="{{ $card->image_url }}" alt="{{ $card->name }}" loading="lazy" width="244" height="340">@endif
                <figcaption>{{ $card->name }}
                    @if($isOwner)<form method="POST" action="{{ route('decks.cards.destroy', [$deck, $card]) }}">@csrf @method('DELETE')<button class="btn btn-small btn-ghost">Remover</button></form>@endif
                </figcaption>
            </figure>
        @endforeach
        </div>
    </section>
    @endif

    <section class="card section-card">
        <div class="section-heading"><div><span class="eyebrow">Lista</span><h2>Cartas</h2></div>
            <div class="section-heading-side">
                <span class="muted">{{ $summary['main'] }} no deck</span>
                @if($hasCollection)
                    <form method="POST" action="{{ route('preferences.deck-ownership') }}" class="ownership-toggle">@csrf @method('PATCH')
                        <input type="hidden" name="show" value="{{ $showOwnership ? 0 : 1 }}">
                        <button class="btn btn-ghost btn-small" aria-pressed="{{ $showOwnership ? 'true' : 'false' }}" title="Mostra, ao lado de cada carta, quantas cópias você tem na coleção">{{ $showOwnership ? 'Ocultar minha coleção' : 'Mostrar o que tenho' }}</button>
                    </form>
                @endif
            </div>
        </div>
        @if($isOwner && ($summary['main'] || $summary['side']))<p class="muted small-note drag-hint">Arraste as cartas entre o deck e o sideboard, ou use os botões ↓ side / ↑ deck.</p>@endif
        <div class="drop-zone" @if($isOwner) data-drop-board="main" data-drop-label="Deck" @endif>
        @forelse($summary['groups'] as $group => $cards)
            <div class="card-group">
                <h3>{{ $group }} <span>{{ $cards->sum('quantity') }}</span></h3>
                @foreach($cards as $card)
                    @include('decks.partials.card-line', ['card' => $card])
                @endforeach
            </div>
        @empty
            <div class="empty-inline"><p>Nenhuma carta ainda.</p>@if($isOwner)<p class="muted">Use a busca ao lado ou cole uma lista exportada do Moxfield, Arena ou LigaMagic.</p>@endif</div>
        @endforelse
        </div>

        @if($summary['sideboard']->isNotEmpty() || ($isOwner && $summary['main']))
            <div class="card-group sideboard drop-zone" @if($isOwner) data-drop-board="side" data-drop-label="Sideboard" @endif>
                <h3>Sideboard <span>{{ $summary['side'] }}</span></h3>
                @forelse($summary['sideboard'] as $card)
                    @include('decks.partials.card-line', ['card' => $card])
                @empty
                    <p class="sideboard-empty">Arraste cartas para cá, ou use “↓ side” em cada carta.</p>
                @endforelse
            </div>
        @endif
    </section>
</div>

<aside class="deck-sidebar">
    @if($isOwner)
    <section class="card section-card" id="add-card">
        <span class="eyebrow">Adicionar cartas</span><h2>Buscar no Scryfall</h2>
        <input type="search" id="card-search" data-async-keep placeholder="Nome da carta, ex.: Lightning Bolt" autocomplete="off" maxlength="100"
               data-endpoint="{{ route('cards.search') }}" data-commander="{{ $deck->isCommander() ? '1' : '0' }}" aria-label="Buscar carta">
        <p class="muted small-note" id="card-search-status" data-async-keep>Digite ao menos 2 letras. Aceita nomes em português. Para uma edição específica, use <code>e:</code> com o código da coleção, ex.: <code>Forest e:ltr</code>.</p>
        <div id="card-results" class="card-results" role="list" data-async-keep></div>
        <form method="POST" action="{{ route('decks.cards.store', $deck) }}" id="add-card-form" hidden>@csrf
            <input type="hidden" name="scryfall_id"><input type="hidden" name="board" value="main"><input type="hidden" name="quantity" value="1">
        </form>
    </section>

    <section class="card section-card">
        <details @if($summary['main'] === 0) open @endif>
            <summary><span class="eyebrow">Importar lista</span><h2>Colar decklist</h2></summary>
            <form method="POST" action="{{ route('decks.cards.import', $deck) }}">@csrf
                <textarea name="list" rows="9" maxlength="20000" required placeholder="4 Lightning Bolt&#10;4 Monastery Swiftspear&#10;20 Mountain&#10;&#10;Sideboard&#10;2 Smash to Smithereens"></textarea>
                <label class="check-label"><input type="checkbox" name="replace" value="1"> Substituir a lista atual</label>
                <button class="btn btn-secondary btn-block">Importar</button>
                <p class="muted small-note">Formatos aceitos: Arena, MTGO, Moxfield e LigaMagic (quantidade + nome, um por linha). Para escolher a edição, coloque o código da coleção entre parênteses: <code>20 Forest (LTR)</code>.</p>
            </form>
        </details>
    </section>
    @endif

    <section class="card section-card">
        <span class="eyebrow">Estatísticas</span><h2>Curva de mana</h2>
        <div class="mana-curve" role="img" aria-label="Curva de mana">
            @foreach($summary['curve'] as $cmc => $count)
                <div class="curve-col"><span class="curve-count">{{ $count ?: '' }}</span><span class="curve-bar" style="height: {{ max(2, round($count / $summary['curve_max'] * 100)) }}%"></span><span class="curve-label">{{ $cmc }}</span></div>
            @endforeach
        </div>
        <div class="type-breakdown">
            @foreach($summary['groups'] as $group => $cards)<div><span>{{ $group }}</span><b>{{ $cards->sum('quantity') }}</b></div>@endforeach
        </div>
    </section>
</aside>
</div>
@endsection
