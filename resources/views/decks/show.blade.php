@extends('layouts.app')
@push('head')<script src="{{ \App\Support\VersionedAsset::url('js/deck-builder.js') }}" defer></script><script src="{{ \App\Support\VersionedAsset::url('js/copy-link.js') }}" defer></script>@endpush
@section('content')
@if(($public ?? false) && ! auth()->check())
<div class="ui-hint deck-public-hint"><x-icon name="info" /><p>Este deck foi compartilhado com você. <a href="{{ route('register') }}">Crie sua conta</a> para montar os seus, comparar e levar para torneios.</p></div>
@endif
@php
    // $public: aberto pelo link público (ML-023), por qualquer pessoa, até sem conta. Mostra o deck como um amigo veria.
    $public = $public ?? false;
    $boardLabel = ['main' => 'Deck', 'side' => 'Sideboard', 'commander' => 'Comandante'];
    // Escolha do comandante: só o dono, em deck Commander. As candidatas são as cartas da própria lista que podem comandar.
    $canPickCommander = $isOwner && $deck->isCommander();
    $commanderCandidates = $canPickCommander ? $summary['commander_candidates'] : collect();
    // Prévia do link no WhatsApp: nome do deck, formato, dono e tamanho; a imagem é a carta da capa.
    $title = $deck->name.' · ManaLeague';
    $og = ['title' => '🃏 '.$deck->name, 'image' => $summary['cover'],
        'description' => implode(' · ', array_filter([$deck->format ?: 'Formato livre', 'Deck de '.$deck->profile->nickname, $summary['main'].' cartas']))];
    // Alerta das cartas que tiram o deck de torneios com as regras do formato: banidas e, no Pauper, inválidas.
    $bannedCount = count($bannedNames);
    $invalidCount = count($invalidNames);
    $blockedHeadline = match (true) {
        $bannedCount > 0 && $invalidCount > 0 => $bannedCount.($bannedCount === 1 ? ' carta banida' : ' cartas banidas').' e '.$invalidCount.($invalidCount === 1 ? ' inválida' : ' inválidas').' no '.$deck->format.'.',
        $invalidCount > 0 => $invalidCount.($invalidCount === 1 ? ' carta inválida no Pauper (nunca saiu como comum).' : ' cartas inválidas no Pauper (nunca saíram como comum).'),
        default => $bannedCount.($bannedCount === 1 ? ' carta banida' : ' cartas banidas').' no '.$deck->format.'.',
    };
@endphp
<header class="deck-hero card" @if($summary['cover']) style="--cover: url('{{ $summary['cover'] }}')" @endif>
    <div class="deck-hero-inner">
        @if($public)
            <span class="eyebrow">Deck compartilhado</span>
        @else
            <a class="back-link" href="{{ $isOwner ? route('decks.index') : route('profiles.show', $deck->profile) }}">← {{ $isOwner ? 'Meus decks' : $deck->profile->nickname }}</a>
        @endif
        <div class="title-line"><span class="format-badge">{{ $deck->format ?: 'Formato livre' }}</span>@if($deck->is_primary)<span class="status status-finished">★ Principal</span>@endif @include('decks.partials.single-set-badge')</div>
        <h1>{{ $deck->name }}</h1>
        <div class="deck-hero-meta">
            @include('partials.color-pips', ['colors' => $deck->colors])
            <span><b>{{ $summary['main'] }}</b> cartas</span>
            @if($summary['side'])<span><b>{{ $summary['side'] }}</b> no side</span>@endif
            <span><b>{{ $summary['lands'] }}</b> terrenos</span>
            <span>CMC médio <b>{{ number_format($summary['avg_cmc'], 2, ',', '') }}</b></span>
            @unless($isOwner)<span class="deck-owner">por <x-avatar :of="$deck->profile" class="ui-avatar deck-owner-avatar" aria-hidden="true" /><b><x-profile-link :of="$deck->profile">{{ $deck->profile->nickname }}</x-profile-link></b></span>@endunless
            @if($deck->cards_updated_at)<span title="{{ $deck->cards_updated_at->format('d/m/Y H:i') }}">Lista atualizada <time datetime="{{ $deck->cards_updated_at->toIso8601String() }}">{{ $deck->cards_updated_at->diffForHumans() }}</time></span>@endif
        </div>
        @if(filled($deck->description))<p class="deck-hero-about">{{ $deck->description }}</p>@endif
    </div>
    {{-- Ações do deck no canto: o menu "..." com compartilhar, exportar, editar e capa. Quem só vê tem o Exportar. --}}
    @if($isOwner)
    <div class="deck-edit">
        <div class="ui-dropdown">
            <button type="button" class="ui-icon-btn" data-menu-button aria-expanded="false" aria-controls="deck-menu" aria-label="Ações do deck" title="Ações do deck"><x-icon name="ellipsis" /></button>
            <div class="ui-menu ui-menu-end" id="deck-menu" hidden>
                <button type="button" class="ui-menu-item" data-dialog-open="deck-share"><x-icon name="link" />Compartilhar @if($deck->share_token)<span class="deck-share-on" title="Link ativo"></span><span class="sr-only">(link ativo)</span>@endif</button>
                @if($exportText !== '')<button type="button" class="ui-menu-item" data-dialog-open="deck-export"><x-icon name="download" />Exportar lista</button>@endif
                <a class="ui-menu-item" href="{{ route('decks.edit', $deck) }}"><x-icon name="pencil" />Editar deck</a>
                <button type="button" class="ui-menu-item" data-dialog-open="deck-description"><x-icon name="info" />{{ filled($deck->description) ? 'Editar descrição' : 'Adicionar descrição' }}</button>
                @if($summary['cover_options']->isNotEmpty())<button type="button" class="ui-menu-item" data-toggle="cover-picker" aria-controls="cover-picker" aria-expanded="false"><x-icon name="image" />Trocar capa</button>@endif
            </div>
        </div>
    </div>
    @elseif($exportText !== '')
    <div class="deck-edit">
        <button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-dialog-open="deck-export"><x-icon name="download" />Exportar lista</button>
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

@if($bannedNames || $invalidNames)
<div class="alert alert-danger ban-alert" role="status"><strong>{{ $blockedHeadline }}</strong> Torneios com as regras padrão do formato não aceitam este deck até a troca{{ $bannedCount + $invalidCount === 1 ? ' dela' : ' delas' }}. Elas estão marcadas com @if($bannedNames)<span class="ban-tag">Banido</span>@endif @if($bannedNames && $invalidNames) e @endif @if($invalidNames)<span class="ban-tag is-invalid">Inválida</span>@endif na lista.</div>
@endif
@if($summary['warnings'])
<div class="alert alert-warning"><strong>Construção:</strong><ul>@foreach($summary['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></div>
@endif

<div class="deck-layout">
<div>
    @if($summary['commanders']->isNotEmpty() || $canPickCommander)
    {{-- Comandante em destaque: um cartão por comandante, com a arte ao fundo, a carta (toque abre a janela da carta), nome, tipo e custo.
         Para o dono de um deck Commander a área também recebe cartas arrastadas da lista. --}}
    <section class="cmdr-zone @if($canPickCommander) drop-zone @endif @if($summary['commanders']->count() > 1) is-pair @endif" aria-label="{{ $summary['commanders']->count() > 1 ? 'Comandantes' : 'Comandante' }}"
             @if($canPickCommander) data-drop-board="commander" data-drop-label="Comandante" @endif>
        @foreach($summary['commanders'] as $card)
            <article class="card cmdr-card" @if($card->art_url) style="--cover: url('{{ $card->art_url }}')" @endif>
                @if($card->image_url)
                    <button type="button" class="cmdr-thumb" aria-haspopup="dialog" aria-label="Ver a carta {{ $card->name }}" title="Ver a carta"
                            data-card-view data-card-name="{{ $card->name }}" data-card-image="{{ $card->image_url }}" data-card-type="{{ $card->type_line }}" data-card-set="{{ strtoupper((string) $card->set_code) }}">
                        <img src="{{ $card->image_url }}" alt="" loading="lazy" width="244" height="340">
                    </button>
                @endif
                <div class="cmdr-info">
                    <span class="eyebrow"><x-icon name="crown" />Comandante</span>
                    <h2>{{ $card->name }} @if(in_array($card->name, $bannedNames, true))<span class="ban-tag" title="Banida no {{ $deck->format }}">Banido</span>@endif</h2>
                    @if($card->type_line)<p class="cmdr-type">{{ $card->type_line }}</p>@endif
                    <span class="card-cost">@foreach($card->manaGroups() as $group)<span class="cost-group">@include('partials.mana-cost', ['symbols' => $group])</span>@endforeach</span>
                    @if($isOwner)
                    <div class="cmdr-actions">
                        @if($commanderCandidates->isNotEmpty())<button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-dialog-open="deck-commander" data-commander-slot="{{ $card->id }}" data-commander-title="Trocar {{ $card->shortName() }}" aria-label="Trocar {{ $card->name }}"><x-icon name="repeat" />Trocar</button>@endif
                        <form method="POST" action="{{ route('decks.cards.commander.remove', [$deck, $card]) }}">@csrf @method('DELETE')<button class="ui-btn ui-btn-ghost ui-btn-sm" aria-label="Mover {{ $card->name }} para o deck">Mover para o deck</button></form>
                        <form method="POST" action="{{ route('decks.cards.destroy', [$deck, $card]) }}">@csrf @method('DELETE')<button class="ui-btn ui-btn-ghost ui-btn-sm" title="Apaga a carta do deck" aria-label="Remover {{ $card->name }} do deck"><x-icon name="x" />Remover</button></form>
                    </div>
                    @endif
                </div>
            </article>
        @endforeach
        @if($canPickCommander && $summary['commanders']->isEmpty())
            <article class="card cmdr-empty">
                <x-icon name="crown" size="20" />
                <div class="cmdr-empty-text">
                    <h2>Escolha o comandante</h2>
                    @if($commanderCandidates->isNotEmpty())
                        <p>{{ $commanderCandidates->count() === 1 ? '1 carta da sua lista pode comandar este deck.' : $commanderCandidates->count().' cartas da sua lista podem comandar este deck.' }}<span class="cmdr-drag-note"> Você também pode arrastar a carta para cá.</span></p>
                    @else
                        <p>Nenhuma carta da sua lista pode comandar. Busque uma criatura lendária para começar.</p>
                    @endif
                </div>
                @if($commanderCandidates->isNotEmpty())
                    <button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-dialog-open="deck-commander"><x-icon name="crown" />Escolher comandante</button>
                @else
                    <button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-dialog-open="deck-add"><x-icon name="search" />Buscar comandante</button>
                @endif
            </article>
        @endif
    </section>
    @endif

    <section class="card section-card">
        {{-- Cabeçalho da lista: à esquerda o título com a contagem embaixo; à direita as ações (a principal por último), centradas na altura do bloco. --}}
        <div class="section-heading deck-list-head"><h2>Cartas <span class="deck-list-count">{{ $summary['main'] }} no deck</span></h2>
            <div class="section-heading-side">
                @if($hasCollection)
                    <form method="POST" action="{{ route('preferences.deck-ownership') }}" class="ownership-toggle">@csrf @method('PATCH')
                        <input type="hidden" name="show" value="{{ $showOwnership ? 0 : 1 }}">
                        <button class="ui-btn ui-btn-outline ui-btn-sm" aria-pressed="{{ $showOwnership ? 'true' : 'false' }}" aria-label="{{ $showOwnership ? 'Ocultar minha coleção' : 'Mostrar o que tenho' }}"
                                title="{{ $showOwnership ? 'Ocultar minha coleção' : 'Mostrar o que tenho' }}: ao lado de cada carta, quantas cópias o deck pede e quantas você tem (deck/coleção)"><x-icon name="{{ $showOwnership ? 'eye' : 'eye-off' }}" /><span class="deck-list-label">Coleção</span></button>
                    </form>
                @endif
                @if($isOwner)<button type="button" class="ui-btn ui-btn-primary ui-btn-sm" data-dialog-open="deck-add"><x-icon name="plus" />Adicionar cartas</button>@endif
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
            <div class="empty-inline"><p>Nenhuma carta ainda.</p>@if($isOwner)<p class="muted">Busque carta por carta ou cole uma lista do Moxfield, Arena ou LigaMagic.</p><button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-dialog-open="deck-add"><x-icon name="plus" />Adicionar cartas</button>@endif</div>
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
    <section class="card section-card">
        {{-- O CMC médio aparece no próprio gráfico: o número ao lado do título e uma linha tracejada na posição dele entre as colunas. --}}
        <div class="curve-head">
            <div><span class="eyebrow">Estatísticas</span><h2>Curva de mana</h2></div>
            @if($summary['avg_cmc'] > 0)<p class="curve-stat"><span>CMC médio</span><b>{{ number_format($summary['avg_cmc'], 2, ',', '') }}</b></p>@endif
        </div>
        <div class="mana-curve" role="img" aria-label="Curva de mana{{ $summary['avg_cmc'] > 0 ? ', CMC médio '.number_format($summary['avg_cmc'], 2, ',', '') : '' }}">
            @foreach($summary['curve'] as $cmc => $count)
                <div class="curve-col"><span class="curve-count">{{ $count ?: '' }}</span><span class="curve-bar" style="height: {{ max(2, round($count / $summary['curve_max'] * 100)) }}%"></span><span class="curve-label">{{ $cmc }}</span></div>
            @endforeach
            @if($summary['avg_cmc'] > 0)<span class="curve-avg" style="--at: {{ round((min($summary['avg_cmc'], 7) + 0.5) / 8, 4) }}" title="CMC médio {{ number_format($summary['avg_cmc'], 2, ',', '') }}"></span>@endif
        </div>
        <div class="type-breakdown">
            @foreach($summary['groups'] as $group => $cards)<div style="--share: {{ $summary['main'] ? round($cards->sum('quantity') / $summary['main'] * 100) : 0 }}%"><span>{{ $group }}</span><b>{{ $cards->sum('quantity') }}</b></div>@endforeach
        </div>
    </section>
</aside>
</div>

{{-- Janela da carta: abre ao tocar no nome de uma carta da lista (deck-builder.js preenche). --}}
<dialog class="ui-dialog deck-card-view" id="deck-card-view" aria-labelledby="deck-card-view-title">
    <header class="ui-dialog-head">
        <h2 id="deck-card-view-title"></h2>
        <button type="button" class="ui-icon-btn" data-dialog-close aria-label="Fechar" title="Fechar"><x-icon name="x" /></button>
    </header>
    <div class="ui-dialog-body">
        <img data-card-view-image alt="" width="488" height="680">
        <div class="deck-card-view-meta"><span data-card-view-type></span><span class="card-cost" data-card-view-cost></span></div>
        <p class="muted small-note" data-card-view-extra></p>
    </div>
</dialog>

{{-- Janelas do deck: exportar (todos que veem), adicionar cartas e compartilhar (só o dono). --}}
@if($exportText !== '')
<dialog class="ui-dialog deck-dialog deck-export" id="deck-export" aria-labelledby="deck-export-title">
    <header class="ui-dialog-head">
        <h2 id="deck-export-title">Exportar lista</h2>
        <button type="button" class="ui-icon-btn" data-dialog-close aria-label="Fechar" title="Fechar"><x-icon name="x" /></button>
    </header>
    <div class="ui-dialog-body">
        <label for="deck-export-text" class="sr-only">Lista do deck em texto</label>
        <textarea id="deck-export-text" rows="10" readonly spellcheck="false">{{ $exportText }}</textarea>
        <p class="muted small-note">A lista copiada funciona na LigaMagic, no Moxfield, no Arena e no importador do ManaLeague.@unless($public) O .csv segue o padrão do ManaBox, com coleção, número e idioma de cada carta.@endunless</p>
    </div>
    <footer class="ui-dialog-foot">
        <button type="button" class="ui-btn ui-btn-primary ui-btn-sm" data-copy-target="#deck-export-text" data-copy-done="Lista copiada">Copiar lista</button>
        @unless($public)<a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ route('decks.export', $deck) }}" download><x-icon name="download" />Baixar .csv</a>@endunless
        <span class="muted small-note" data-copy-status role="status"></span>
    </footer>
</dialog>
@endif

@if($isOwner)
{{-- Adicionar cartas: uma janela com dois caminhos, buscar carta por carta ou colar uma lista.
     data-async-keep: ela continua aberta (e com os resultados) enquanto a pessoa adiciona várias cartas seguidas. --}}
<dialog class="ui-dialog deck-dialog deck-add" id="deck-add" aria-labelledby="deck-add-title" data-async-keep>
    <header class="ui-dialog-head">
        <h2 id="deck-add-title">Adicionar cartas</h2>
        <button type="button" class="ui-icon-btn" data-dialog-close aria-label="Fechar" title="Fechar"><x-icon name="x" /></button>
    </header>
    <div class="deck-add-tabs" role="tablist" aria-label="Como adicionar">
        <button type="button" role="tab" id="deck-add-tab-search" aria-controls="deck-add-search" aria-selected="true" data-add-tab><x-icon name="search" />Buscar cartas</button>
        <button type="button" role="tab" id="deck-add-tab-list" aria-controls="deck-add-list" aria-selected="false" data-add-tab><x-icon name="upload" />Colar lista</button>
    </div>
    <div class="ui-dialog-body" id="deck-add-search" role="tabpanel" aria-labelledby="deck-add-tab-search">
        <div class="deck-add-bar">
            <input type="search" id="card-search" placeholder="Nome da carta, ex.: Lightning Bolt" autocomplete="off" maxlength="100"
                   data-endpoint="{{ route('cards.search') }}" data-commander="{{ $deck->isCommander() ? '1' : '0' }}" aria-label="Buscar carta">
            @if($hasCollection)
            <div class="search-source" role="group" aria-label="Onde buscar">
                <button type="button" class="btn btn-small btn-secondary" aria-pressed="true" data-source="scryfall" data-search-source="{{ route('cards.search') }}"
                        data-placeholder="Nome da carta, ex.: Lightning Bolt" data-hint="Digite ao menos 2 letras. Busca todas as cartas do Scryfall, também pelo nome em português.">Scryfall</button>
                <button type="button" class="btn btn-small btn-ghost" aria-pressed="false" data-source="collection" data-search-source="{{ route('collection.search') }}"
                        data-placeholder="Nome da carta na sua coleção" data-hint="Digite ao menos 2 letras. Busca pelo nome em inglês entre as cartas da sua coleção.">Minha coleção</button>
            </div>
            @endif
            <label class="check-label foil-pick"><input type="checkbox" id="add-foil"> Adicionar como foil</label>
        </div>
        <p class="muted small-note" id="card-search-status" role="status">Digite ao menos 2 letras. Aceita nomes em português. Para uma edição específica, use <code>e:</code> com o código da coleção, ex.: <code>Forest e:ltr</code>.</p>
        <div id="card-results" class="card-results" role="list"></div>
        <form method="POST" action="{{ route('decks.cards.store', $deck) }}" id="add-card-form" hidden>@csrf
            <input type="hidden" name="scryfall_id"><input type="hidden" name="board" value="main"><input type="hidden" name="quantity" value="1"><input type="hidden" name="foil" value="0">
        </form>
    </div>
    <form method="POST" action="{{ route('decks.cards.import', $deck) }}" id="deck-add-list" role="tabpanel" aria-labelledby="deck-add-tab-list" data-add-import hidden>@csrf
        <div class="ui-dialog-body">
            <label for="deck-import-text" class="sr-only">Lista para importar</label>
            <textarea id="deck-import-text" name="list" rows="9" maxlength="20000" required placeholder="4 Lightning Bolt&#10;4 Monastery Swiftspear&#10;20 Mountain&#10;&#10;Sideboard&#10;2 Smash to Smithereens"></textarea>
            <label class="check-label"><input type="checkbox" name="replace" value="1"> Substituir a lista atual</label>
            <p class="muted small-note">Formatos aceitos: Arena, MTGO, Moxfield e LigaMagic (quantidade + nome, um por linha). Para escolher a edição, coloque o código da coleção entre parênteses: <code>20 Forest (LTR)</code>.</p>
        </div>
        <footer class="ui-dialog-foot">
            <button class="ui-btn ui-btn-primary ui-btn-sm"><x-icon name="upload" />Importar lista</button>
            <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" data-dialog-close>Cancelar</button>
        </footer>
    </form>
</dialog>

<dialog class="ui-dialog deck-dialog" id="deck-description" aria-labelledby="deck-description-title">
    <header class="ui-dialog-head">
        <h2 id="deck-description-title">Descrição do deck</h2>
        <button type="button" class="ui-icon-btn" data-dialog-close aria-label="Fechar" title="Fechar"><x-icon name="x" /></button>
    </header>
    <form method="POST" action="{{ route('decks.description', $deck) }}">@csrf @method('PATCH')
        <div class="ui-dialog-body">
            <label for="deck-description-text" class="sr-only">Descrição do deck</label>
            <textarea id="deck-description-text" name="description" rows="2" maxlength="{{ \App\Models\Deck::DESCRIPTION_MAX }}" placeholder="Em uma frase, como este deck joga?">{{ $deck->description }}</textarea>
            <p class="muted small-note">Aparece no topo do deck para todos que podem vê-lo. Até {{ \App\Models\Deck::DESCRIPTION_MAX }} caracteres; deixe em branco para remover.</p>
        </div>
        <footer class="ui-dialog-foot">
            <button class="ui-btn ui-btn-primary ui-btn-sm">Salvar descrição</button>
            <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" data-dialog-close>Cancelar</button>
        </footer>
    </form>
</dialog>

@if($commanderCandidates->isNotEmpty())
{{-- Escolher comandante: as cartas da lista que podem comandar. A coroa de uma carta abre só ela; "Trocar" mostra só a troca daquele comandante (deck-builder.js). --}}
<dialog class="ui-dialog deck-dialog cmdr-dialog" id="deck-commander" aria-labelledby="deck-commander-title">
    <header class="ui-dialog-head">
        <h2 id="deck-commander-title">Escolher comandante</h2>
        <button type="button" class="ui-icon-btn" data-dialog-close aria-label="Fechar" title="Fechar"><x-icon name="x" /></button>
    </header>
    <div class="ui-dialog-body">
        <p class="muted small-note">Criaturas e planeswalkers lendários e Backgrounds da sua lista.@if($summary['commanders']->isNotEmpty()) O comandante trocado volta para o deck.@endif</p>
        <div class="card-results" role="list">
            @foreach($commanderCandidates as $candidate)
                <div class="card-result" role="listitem" data-commander-row="{{ $candidate->id }}">
                    @if($candidate->image_url)<img src="{{ $candidate->image_url }}" alt="" loading="lazy" width="61" height="85">@endif
                    <div class="card-result-info">
                        <strong>{{ $candidate->name }}</strong>
                        <small>{{ $candidate->type_line }} · {{ $boardLabel[$candidate->board] }}</small>
                        <div class="card-result-actions">
                            @if($summary['commanders']->isEmpty())
                                <form method="POST" action="{{ route('decks.cards.commander', [$deck, $candidate]) }}">@csrf<button class="ui-btn ui-btn-outline ui-btn-sm"><x-icon name="crown" />Tornar comandante</button></form>
                            @endif
                            @foreach($summary['commanders'] as $current)
                                <form method="POST" action="{{ route('decks.cards.commander', [$deck, $candidate]) }}" data-commander-option="{{ $current->id }}">@csrf<input type="hidden" name="replace" value="{{ $current->id }}"><button class="ui-btn ui-btn-outline ui-btn-sm" aria-label="Colocar {{ $candidate->name }} no lugar de {{ $current->name }}"><x-icon name="repeat" />No lugar de {{ $current->shortName() }}</button></form>
                            @endforeach
                            @if($summary['commanders']->isNotEmpty() && $summary['commanders']->count() < \App\Models\DeckCard::MAX_COMMANDERS)
                                <form method="POST" action="{{ route('decks.cards.commander', [$deck, $candidate]) }}" data-commander-option="partner">@csrf<button class="ui-btn ui-btn-ghost ui-btn-sm" aria-label="Adicionar {{ $candidate->name }} como parceiro"><x-icon name="plus" />Adicionar como parceiro</button></form>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</dialog>
@endif

{{-- Link público (ML-023): o dono cria um link para mandar o deck a qualquer pessoa, mesmo sem conta; pode trocar ou desativar. --}}
<dialog class="ui-dialog deck-dialog deck-share" id="deck-share" aria-labelledby="deck-share-title" @if(session('deck_dialog') === 'share') data-open-on-load @endif>
    <header class="ui-dialog-head">
        <h2 id="deck-share-title">Link do deck</h2>
        <button type="button" class="ui-icon-btn" data-dialog-close aria-label="Fechar" title="Fechar"><x-icon name="x" /></button>
    </header>
    @if($deck->share_token)
    <div class="ui-dialog-body">
        <label for="deck-share-url" class="sr-only">Link do deck</label>
        <input id="deck-share-url" type="text" readonly value="{{ $deck->shareUrl() }}">
        <p class="muted small-note">Quem tiver o link vê a lista, mesmo sem conta no ManaLeague. Link ativo desde {{ $deck->shared_at?->timezone(config('app.timezone'))->format('d/m/Y') }}.</p>
    </div>
    <footer class="ui-dialog-foot">
        <button type="button" class="ui-btn ui-btn-primary ui-btn-sm" data-copy-target="#deck-share-url" data-copy-done="Link copiado">Copiar link</button>
        <form method="POST" action="{{ route('decks.share', $deck) }}">@csrf<button class="ui-btn ui-btn-outline ui-btn-sm" data-confirm="Gerar um link novo? O link atual para de funcionar.">Gerar novo link</button></form>
        <form method="POST" action="{{ route('decks.unshare', $deck) }}">@csrf @method('DELETE')<button class="ui-btn ui-btn-ghost ui-btn-sm" data-confirm="Desativar o link? Quem tem o link não verá mais o deck.">Desativar</button></form>
        <span class="muted small-note" data-copy-status role="status"></span>
    </footer>
    @else
    <div class="ui-dialog-body">
        <p class="muted small-note">Crie um link para mandar este deck para quem quiser, mesmo para quem não tem conta. Ele não aparece em buscas e você pode desativar quando quiser.</p>
    </div>
    <footer class="ui-dialog-foot">
        <form method="POST" action="{{ route('decks.share', $deck) }}">@csrf<button class="ui-btn ui-btn-primary ui-btn-sm"><x-icon name="link" />Criar link</button></form>
    </footer>
    @endif
</dialog>
@endif
@endsection
