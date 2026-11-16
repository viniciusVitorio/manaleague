@extends('layouts.app')
@section('content')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/hub.css') }}">@endpush
@php
    $colorOptions = ['W','U','B','R','G','WU','UB','BR','RG','GW','WB','UR','BG','RW','GU','WUB','UBR','BRG','RGW','GWU','WUBRG','C'];
    $played = $stats['wins'] + $stats['losses'];
@endphp
<header class="page-head">
    <div><h1>Meus decks</h1><p>Monte, organize e acompanhe o desempenho dos seus decks.</p></div>
    <div class="hub-actions">
        <button type="button" class="ui-btn ui-btn-primary" data-dialog-open="novo-deck"><x-icon name="plus" />Novo deck</button>
    </div>
</header>

<section class="dash-stats dash-stats-3 deck-hub-stats" aria-label="Resumo dos decks">
    <x-ui.stat label="Decks cadastrados" :value="$stats['decks']" icon="layers" :hint="$stats['decks'] ? $stats['visible'].' '.($stats['visible'] === 1 ? 'visível' : 'visíveis').' para amigos · '.($stats['decks'] - $stats['visible']).' '.($stats['decks'] - $stats['visible'] === 1 ? 'privado' : 'privados') : 'Crie o primeiro em “Novo deck”'" />
    <x-ui.stat label="Formato favorito" :value="$stats['top_format'] ?? '—'" icon="trophy" :hint="$stats['top_format'] ? $stats['top_format_count'].' '.($stats['top_format_count'] === 1 ? 'deck' : 'decks') : null" />
    @if($hasCollection)
        <x-ui.stat label="Prontos na coleção" :value="$stats['complete'].' de '.$stats['decks']" icon="circle-check" :hint="'Decks com todas as cartas na sua coleção'" :href="route('collection.index')" />
    @else
        <x-ui.stat label="Em torneios" :value="$played ? $stats['wins'].'V – '.$stats['losses'].'D' : '—'" icon="swords" :hint="$stats['tournaments'] ? $stats['tournaments'].' '.($stats['tournaments'] === 1 ? 'inscrição' : 'inscrições').' com decks salvos' : 'Escolha um deck salvo ao entrar num torneio'" />
    @endif
</section>

{{-- Barra da lista: filtros de formato à esquerda, ferramentas à direita. Os decks ocupam a largura toda (sem coluna lateral). --}}
<div class="deck-hub-bar">
    @if($formats->count() > 1)
        <nav class="deck-filter" aria-label="Filtrar por formato">
            <a class="ui-btn ui-btn-sm {{ $format ? 'ui-btn-ghost' : 'ui-btn-primary' }}" href="{{ route('decks.index') }}" @unless($format) aria-current="true" @endunless>Todos <span>{{ $stats['decks'] }}</span></a>
            @foreach($formats as $name => $count)
                <a class="ui-btn ui-btn-sm {{ $format === $name ? 'ui-btn-primary' : 'ui-btn-ghost' }}" href="{{ route('decks.index', ['format' => $name]) }}" @if($format === $name) aria-current="true" @endif>{{ $name }} <span>{{ $count }}</span></a>
            @endforeach
        </nav>
    @endif
    <nav class="deck-hub-tools" aria-label="Ferramentas de decks">
        <a class="ui-btn ui-btn-ghost ui-btn-sm" href="{{ route('collection.index') }}" title="O que você tem e o que falta para cada deck"><x-icon name="library" />Coleção</a>
        <a class="ui-btn ui-btn-ghost ui-btn-sm" href="{{ route('decks.history') }}" title="Versões salvas dos seus decks"><x-icon name="clock" />Histórico</a>
        <a class="ui-btn ui-btn-ghost ui-btn-sm" href="{{ route('decks.compare') }}" title="Diferenças entre duas listas"><x-icon name="layers" />Comparar</a>
    </nav>
</div>

@if($decks->isEmpty())
    <div class="ui-empty">
        <span class="ui-empty-icon"><x-icon name="layers" :size="18" /></span>
        <div><p class="ui-empty-title">{{ $format ? 'Nenhum deck de '.$format.'.' : 'Nenhum deck ainda.' }}</p><p class="ui-empty-text">Crie um deck, depois busque as cartas no Scryfall ou cole uma lista do Moxfield, Arena ou LigaMagic.</p></div>
        <button type="button" class="ui-btn ui-btn-outline ui-btn-sm" data-dialog-open="novo-deck">Novo deck</button>
    </div>
@else
    <div class="deck-cards deck-hub-cards">
    @foreach($decks as $d)
        @include('decks.partials.deck-card', ['d' => $d, 'manage' => true])
    @endforeach
    </div>
@endif

{{-- "Novo deck" abre em janela. Reabre sozinha quando o link traz ?novo=1 ou quando o formulário volta com erro. --}}
<dialog class="ui-dialog deck-dialog" id="novo-deck" aria-labelledby="novo-deck-title" @if(request()->boolean('novo') || ($errors->any() && old('_token'))) data-open-on-load @endif>
    <header class="ui-dialog-head">
        <h2 id="novo-deck-title">Novo deck</h2>
        <button type="button" class="ui-icon-btn" data-dialog-close aria-label="Fechar" title="Fechar"><x-icon name="x" /></button>
    </header>
    <form method="POST" action="{{ route('decks.store') }}">@csrf
        <div class="ui-dialog-body deck-new">
            <label>Nome do deck<input name="name" maxlength="100" required placeholder="Rakdos Vampires" value="{{ old('name') }}"></label>
            <div class="deck-new-row">
                <label>Formato<select name="format"><option value="">Livre</option>@foreach(\App\Models\Deck::FORMATS as $f)<option value="{{ $f }}" @selected(old('format') === $f)>{{ $f }}</option>@endforeach</select></label>
                <label>Cores<select name="colors"><option value="">Definir pelas cartas</option>@foreach($colorOptions as $color)<option value="{{ $color }}" @selected(old('colors') === $color)>{{ $color }}</option>@endforeach</select></label>
            </div>
            <p class="muted small-note">As cores são calculadas automaticamente quando você adiciona cartas.</p>
            <label class="check-label"><input type="checkbox" name="is_primary" value="1" @checked(old('is_primary'))> Usar como principal</label>
            <input type="hidden" name="is_visible_to_friends" value="0">
            <label class="check-label"><input type="checkbox" name="is_visible_to_friends" value="1" @checked(old('is_visible_to_friends'))> Mostrar este deck para meus amigos</label>
            <p class="muted small-note">Novos decks começam privados; marque para compartilhar com amigos.</p>
        </div>
        <footer class="ui-dialog-foot deck-new-foot">
            <button type="button" class="ui-btn ui-btn-ghost" data-dialog-close>Cancelar</button>
            <button class="ui-btn ui-btn-primary">Criar e adicionar cartas</button>
        </footer>
    </form>
</dialog>
@endsection
