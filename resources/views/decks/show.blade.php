@extends('layouts.app')
@push('head')<script src="{{ asset('js/deck-builder.js') }}" defer></script><script src="{{ asset('vendor/tesseract/tesseract.min.js') }}" defer></script><script src="{{ asset('js/card-scanner.js') }}" defer></script>@endpush
@section('content')
@php
    $boardLabel = ['main' => 'Deck', 'side' => 'Sideboard', 'commander' => 'Comandante'];
@endphp
<header class="deck-hero card" @if($summary['cover']) style="--cover: url('{{ $summary['cover'] }}')" @endif>
    <div class="deck-hero-inner">
        <a class="back-link" href="{{ $isOwner ? route('profiles.edit') : route('profiles.show', $deck->profile) }}">← {{ $isOwner ? 'Meus decks' : $deck->profile->nickname }}</a>
        <div class="title-line"><span class="format-badge">{{ $deck->format ?: 'Formato livre' }}</span>@if($deck->is_primary)<span class="status status-finished">★ Principal</span>@endif</div>
        <h1>{{ $deck->name }}</h1>
        <div class="deck-hero-meta">
            @include('partials.color-pips', ['colors' => $deck->colors])
            <span><b>{{ $summary['main'] }}</b> cartas</span>
            @if($summary['side'])<span><b>{{ $summary['side'] }}</b> no side</span>@endif
            <span><b>{{ $summary['lands'] }}</b> terrenos</span>
            <span>CMC médio <b>{{ number_format($summary['avg_cmc'], 2, ',', '') }}</b></span>
            @unless($isOwner)<span>por <b>{{ $deck->profile->nickname }}</b></span>@endunless
        </div>
    </div>
    @if($isOwner)<a class="btn btn-ghost btn-small deck-edit" href="{{ route('decks.edit', $deck) }}">Editar deck</a>@endif
</header>

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
        <div class="section-heading"><div><span class="eyebrow">Lista</span><h2>Cartas</h2></div><span class="muted">{{ $summary['main'] }} no deck</span></div>
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

        @if($summary['sideboard']->isNotEmpty())
            <div class="card-group sideboard">
                <h3>Sideboard <span>{{ $summary['side'] }}</span></h3>
                @foreach($summary['sideboard'] as $card)
                    @include('decks.partials.card-line', ['card' => $card])
                @endforeach
            </div>
        @endif
    </section>
</div>

<aside class="deck-sidebar">
    @if($isOwner)
    <section class="card section-card" id="scan-cards" data-store-endpoint="{{ route('decks.cards.store', $deck) }}" data-worker="{{ asset('vendor/tesseract/worker.min.js') }}" data-core="{{ asset('vendor/tesseract') }}/" data-language="{{ asset('vendor/tesseract') }}/">
        <span class="eyebrow">Câmera</span><h2>Escanear carta</h2>
        <p class="muted small-note">Aponte para a carta. O OCR roda neste aparelho; confirme antes de adicionar.</p>
        <button class="btn btn-secondary btn-block" id="scan-capture" type="button">Abrir câmera</button>
        <div class="scan-viewfinder"><video id="scan-video" playsinline autoplay hidden></video><span aria-hidden="true"></span></div>
        <canvas id="scan-canvas" hidden></canvas>
        <div id="scan-results" aria-live="polite"></div>
    </section>
    @endif

    @if($isOwner)
    <section class="card section-card" id="add-card">
        <span class="eyebrow">Adicionar cartas</span><h2>Buscar no Scryfall</h2>
        <input type="search" id="card-search" placeholder="Nome da carta, ex.: Lightning Bolt" autocomplete="off" maxlength="100"
               data-endpoint="{{ route('cards.search') }}" data-commander="{{ $deck->isCommander() ? '1' : '0' }}" aria-label="Buscar carta">
        <p class="muted small-note" id="card-search-status">Digite ao menos 2 letras. Aceita nomes em português.</p>
        <div id="card-results" class="card-results" role="list"></div>
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
                <p class="muted small-note">Formatos aceitos: Arena, MTGO, Moxfield e LigaMagic (quantidade + nome, um por linha).</p>
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
