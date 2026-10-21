@extends('layouts.app')
@section('content')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/hub.css') }}">@endpush
<header class="page-head">
    <div><h1>Minha coleção</h1><p>Suas cartas, quanto falta pros seus decks e o que dá pra trocar com os amigos.</p></div>
    <div class="hub-actions">
        <a class="ui-btn ui-btn-outline" href="{{ route('collection.export') }}"><x-icon name="download" />Exportar lista</a>
        <a class="ui-btn ui-btn-primary" href="#adicionar"><x-icon name="plus" />Adicionar cartas</a>
    </div>
</header>

<section class="dash-stats" aria-label="Resumo da coleção">
    <x-ui.stat label="Cartas" :value="number_format($stats['cards'], 0, ',', '.')" icon="library" :hint="$stats['unique'].' diferentes'" />
    <x-ui.stat label="Valor estimado" :value="'US$ '.number_format($stats['value'], 2, ',', '.')" icon="dollar" :hint="$stats['cards'] ? ($stats['priced'] < $stats['cards'] ? 'Preço Scryfall · '.($stats['cards'] - $stats['priced']).' sem preço' : 'Preço de referência do Scryfall') : null" />
    <x-ui.stat label="Foils" :value="$stats['foils']" icon="sparkles" />
    <x-ui.stat label="Para troca" :value="$stats['trade']" icon="repeat" :hint="$stats['trade'] ? 'Amigos veem no seu perfil' : 'Marque cópias na lista abaixo'" />
</section>

<div class="hub-grid">
<div class="hub-main">
    <x-ui.card title="Cartas" icon="library" :meta="$items->total() ? $items->total().' itens' : null">
        <form method="GET" class="hub-filters" role="search">
            <label class="hub-search"><x-icon name="search" /><input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Filtrar por nome ou coleção (ex.: MH3)" aria-label="Filtrar cartas"></label>
            <select name="show" aria-label="Mostrar">@foreach(['all' => 'Todas', 'trade' => 'Para troca', 'foil' => 'Só foils'] as $v => $l)<option value="{{ $v }}" @selected(($filters['show'] ?? 'all') === $v)>{{ $l }}</option>@endforeach</select>
            <select name="sort" aria-label="Ordenar">@foreach(['name' => 'Nome', 'value' => 'Mais valiosas', 'recent' => 'Recentes'] as $v => $l)<option value="{{ $v }}" @selected(($filters['sort'] ?? 'name') === $v)>{{ $l }}</option>@endforeach</select>
            <button class="ui-btn ui-btn-outline">Filtrar</button>
        </form>
        @if($items->isEmpty())
            <x-ui.empty icon="library" :title="($filters['q'] ?? null) || ($filters['show'] ?? 'all') !== 'all' ? 'Nada com esse filtro.' : 'Sua coleção está vazia.'" text="Busque pelo nome da carta ou cole uma lista inteira em Adicionar cartas." />
        @else
            <div class="binder">
            @foreach($items as $item)
                <article class="binder-card">
                    <div class="binder-img">@if($item->image_url)<img src="{{ $item->image_url }}" alt="" loading="lazy" width="146" height="204">@else<span>{{ $item->name }}</span>@endif
                        @if($item->foil)<span class="binder-flag">Foil</span>@endif
                        @if($item->for_trade)<span class="binder-flag is-trade">{{ $item->for_trade }} p/ troca</span>@endif
                    </div>
                    <div class="binder-info"><strong title="{{ $item->name }}">{{ $item->name }}</strong><small>{{ $item->set_code }} · {{ $item->condition }}@if($item->price_usd) · US$ {{ number_format((float) $item->price_usd, 2, ',', '.') }}@endif</small></div>
                    <form method="POST" action="{{ route('collection.update', $item) }}" class="binder-edit">@csrf @method('PATCH')
                        <label>Qtd<input type="number" name="quantity" min="1" max="9999" value="{{ $item->quantity }}" required aria-label="Quantidade de {{ $item->name }}"></label>
                        <label>Troca<input type="number" name="for_trade" min="0" max="{{ $item->quantity }}" value="{{ $item->for_trade }}" aria-label="Cópias de {{ $item->name }} para troca"></label>
                        <button class="ui-btn ui-btn-outline ui-btn-sm">Salvar</button>
                    </form>
                    <form method="POST" action="{{ route('collection.destroy', $item) }}" class="binder-del">@csrf @method('DELETE')<button class="ui-btn ui-btn-ghost ui-btn-sm" aria-label="Remover {{ $item->name }}"><x-icon name="trash" /></button></form>
                </article>
            @endforeach
            </div>
            {{ $items->links() }}
        @endif
    </x-ui.card>

    <x-ui.card title="Adicionar cartas" icon="plus" id="adicionar">
        <div class="add-grid">
            <form method="POST" action="{{ route('collection.store') }}" id="collection-add" class="add-one">@csrf
                <p class="ui-card-meta">Buscar pelo nome</p>
                <label class="hub-search"><x-icon name="search" /><input type="search" id="collection-search" data-endpoint="{{ route('cards.search') }}" placeholder="Ex.: Counterspell, Brainstorm…" autocomplete="off" aria-label="Buscar carta pelo nome" aria-controls="collection-results"></label>
                <p class="muted small" id="collection-search-status" aria-live="polite"></p>
                <div id="collection-results" class="pick-list" role="listbox" aria-label="Resultados"></div>
                <input type="hidden" name="scryfall_id" value="{{ old('scryfall_id') }}">
                <div class="add-row">
                    <label>Qtd<input type="number" name="quantity" min="1" max="9999" value="1" required></label>
                    <label>Condição<select name="condition">@foreach(\App\Models\CollectionItem::CONDITIONS as $c)<option>{{ $c }}</option>@endforeach</select></label>
                    <label>Acabamento<select name="foil"><option value="0">Normal</option><option value="1">Foil</option></select></label>
                </div>
                <p class="selected-card muted small" id="collection-picked">Escolha uma carta na busca.</p>
                <button class="ui-btn ui-btn-primary" id="collection-add-btn" disabled>Adicionar à coleção</button>
                <details class="small muted"><summary>Tenho o ID da impressão do Scryfall</summary><input name="scryfall_id_manual" placeholder="xxxxxxxx-xxxx-…" maxlength="36" data-manual-id aria-label="ID da impressão"></details>
            </form>
            <form method="POST" action="{{ route('collection.import') }}" class="add-bulk">@csrf
                <p class="ui-card-meta">Colar uma lista</p>
                <textarea name="list" rows="9" maxlength="20000" placeholder="4 Counterspell&#10;1 Brainstorm (MH3)&#10;2x Ponder" aria-label="Lista de cartas">{{ old('list') }}</textarea>
                <div class="add-row">
                    <label>Condição<select name="condition">@foreach(\App\Models\CollectionItem::CONDITIONS as $c)<option>{{ $c }}</option>@endforeach</select></label>
                    <label>Acabamento<select name="foil"><option value="0">Normal</option><option value="1">Foil</option></select></label>
                </div>
                <button class="ui-btn ui-btn-outline"><x-icon name="upload" />Importar lista</button>
                <p class="muted small">Aceita o formato do Arena, MTGO, Moxfield e LigaMagic (uma carta por linha). Até {{ \App\Services\Decks\CollectionService::MAX_IMPORT_LINES }} linhas.</p>
            </form>
        </div>
        <p class="muted small">Condição: NM nova · LP pouco jogada · MP moderada · HP muito jogada · DMG danificada. O valor usa o preço de referência do Scryfall em dólar.</p>
    </x-ui.card>
</div>

<aside class="hub-side">
    <x-ui.card title="Meus decks" icon="layers" meta="quanto você já tem">
        @forelse($coverage as $c)
            <a class="cover-row" href="{{ route('collection.missing', $c['deck']) }}">
                <span class="cover-name">{{ $c['deck']->name }}<small>{{ $c['missing'] ? 'faltam '.$c['missing'].' cartas' : 'completo' }}</small></span>
                <span class="cover-pct">{{ $c['percent'] }}%</span>
                <span class="cover-bar" aria-hidden="true"><span style="width: {{ $c['percent'] }}%"></span></span>
            </a>
        @empty
            <x-ui.empty icon="layers" title="Nenhum deck ainda." text="Monte um deck e veja aqui quanto dele você já tem." :href="route('profiles.edit').'#novo-deck'" action="Novo deck" />
        @endforelse
    </x-ui.card>

    <x-ui.card title="Amigos têm pra troca" icon="repeat">
        @forelse($matches as $m)
            <div class="trade-row">
                <strong>{{ $m['name'] }}</strong><small>você precisa de {{ $m['missing'] }}</small>
                <ul>@foreach($m['offers'] as $o)<li><a href="{{ route('profiles.show', $o['profile']) }}#trocas">{{ $o['profile']->nickname }}</a> · {{ $o['quantity'] }}× {{ $o['set_code'] }}{{ $o['foil'] ? ' foil' : '' }} {{ $o['condition'] }}</li>@endforeach</ul>
            </div>
        @empty
            <p class="muted small">Quando um amigo marcar para troca uma carta que falta nos seus decks, ela aparece aqui.</p>
        @endforelse
    </x-ui.card>

    <x-ui.card title="Lista de desejos" icon="heart" :meta="$wishlist->count() ? $wishlist->count().' cartas' : null">
        @forelse($wishlist->take(15) as $w)
            <div class="wish-row"><span>{{ $w['missing'] }}× {{ $w['name'] }}</span><small title="{{ implode(', ', $w['decks']) }}">{{ $w['decks'][0] }}{{ count($w['decks']) > 1 ? ' +'.(count($w['decks']) - 1) : '' }}</small></div>
        @empty
            <p class="muted small">Tudo o que falta para montar seus decks aparece aqui automaticamente.</p>
        @endforelse
        @if($wishlist->count() > 15)<p class="muted small">e mais {{ $wishlist->count() - 15 }}…</p>@endif
    </x-ui.card>
</aside>
</div>
<script src="{{ \App\Support\VersionedAsset::url('js/collection.js') }}" defer></script>
@endsection
