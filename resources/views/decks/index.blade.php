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
        <a class="ui-btn ui-btn-outline" href="{{ route('decks.compare') }}"><x-icon name="layers" />Comparar</a>
        <a class="ui-btn ui-btn-primary" href="#novo-deck"><x-icon name="plus" />Novo deck</a>
    </div>
</header>

<section class="dash-stats dash-stats-3" aria-label="Resumo dos decks">
    <x-ui.stat label="Decks cadastrados" :value="$stats['decks']" icon="layers" :hint="$stats['decks'] ? $stats['visible'].' '.($stats['visible'] === 1 ? 'visível' : 'visíveis').' para amigos · '.($stats['decks'] - $stats['visible']).' '.($stats['decks'] - $stats['visible'] === 1 ? 'privado' : 'privados') : 'Crie o primeiro ao lado'" />
    <x-ui.stat label="Formato favorito" :value="$stats['top_format'] ?? '—'" icon="trophy" :hint="$stats['top_format'] ? $stats['top_format_count'].' '.($stats['top_format_count'] === 1 ? 'deck' : 'decks') : null" />
    @if($hasCollection)
        <x-ui.stat label="Prontos na coleção" :value="$stats['complete'].' de '.$stats['decks']" icon="circle-check" :hint="'Decks com todas as cartas na sua coleção'" :href="route('collection.index')" />
    @else
        <x-ui.stat label="Em torneios" :value="$played ? $stats['wins'].'V – '.$stats['losses'].'D' : '—'" icon="swords" :hint="$stats['tournaments'] ? $stats['tournaments'].' '.($stats['tournaments'] === 1 ? 'inscrição' : 'inscrições').' com decks salvos' : 'Escolha um deck salvo ao entrar num torneio'" />
    @endif
</section>

<div class="hub-grid">
<div class="hub-main">
    <x-ui.card title="Decks" icon="layers" :meta="$decks->count() ? $decks->count().' '.($decks->count() === 1 ? 'deck' : 'decks') : null">
        @if($formats->count() > 1)
            <nav class="deck-filter" aria-label="Filtrar por formato">
                <a class="ui-btn ui-btn-sm {{ $format ? 'ui-btn-ghost' : 'ui-btn-primary' }}" href="{{ route('decks.index') }}" @unless($format) aria-current="true" @endunless>Todos <span>{{ $stats['decks'] }}</span></a>
                @foreach($formats as $name => $count)
                    <a class="ui-btn ui-btn-sm {{ $format === $name ? 'ui-btn-primary' : 'ui-btn-ghost' }}" href="{{ route('decks.index', ['format' => $name]) }}" @if($format === $name) aria-current="true" @endif>{{ $name }} <span>{{ $count }}</span></a>
                @endforeach
            </nav>
        @endif

        @if($decks->isEmpty())
            <x-ui.empty icon="layers" :title="$format ? 'Nenhum deck de '.$format.'.' : 'Nenhum deck ainda.'" text="Crie um deck, depois busque as cartas no Scryfall ou cole uma lista do Moxfield, Arena ou LigaMagic." href="#novo-deck" action="Novo deck" />
        @else
            <div class="deck-cards">
            @foreach($decks as $d)
                @include('decks.partials.deck-card', ['d' => $d, 'manage' => true])
            @endforeach
            </div>
        @endif
    </x-ui.card>
</div>

<aside class="hub-side">
    <x-ui.card title="Novo deck" icon="plus" id="novo-deck">
        <form method="POST" action="{{ route('decks.store') }}" class="deck-new">@csrf
            <label>Nome do deck<input name="name" maxlength="100" required placeholder="Rakdos Vampires" value="{{ old('name') }}"></label>
            <label>Formato<select name="format"><option value="">Livre</option>@foreach(\App\Models\Deck::FORMATS as $f)<option value="{{ $f }}" @selected(old('format') === $f)>{{ $f }}</option>@endforeach</select></label>
            <label>Cores <small>calculadas automaticamente quando você adiciona cartas</small><select name="colors"><option value="">Definir pelas cartas</option>@foreach($colorOptions as $color)<option value="{{ $color }}">{{ $color }}</option>@endforeach</select></label>
            <label class="check-label"><input type="checkbox" name="is_primary" value="1"> Usar como principal</label>
            <input type="hidden" name="is_visible_to_friends" value="0">
            <label class="check-label"><input type="checkbox" name="is_visible_to_friends" value="1" @checked(old('is_visible_to_friends'))> Mostrar este deck para meus amigos</label>
            <p class="muted small">Novos decks começam privados; marque para compartilhar com amigos.</p>
            <button class="ui-btn ui-btn-primary">Criar e adicionar cartas</button>
        </form>
    </x-ui.card>

    <x-ui.card title="Ferramentas" icon="settings">
        <nav class="deck-tools-list" aria-label="Ferramentas de decks">
            <a href="{{ route('collection.index') }}"><x-icon name="library" /><span><strong>Coleção</strong><small>O que você tem e o que falta para cada deck</small></span></a>
            <a href="{{ route('decks.history') }}"><x-icon name="clock" /><span><strong>Histórico de versões</strong><small>Fotos salvas dos seus decks</small></span></a>
            <a href="{{ route('decks.compare') }}"><x-icon name="layers" /><span><strong>Comparador</strong><small>Diferenças entre duas listas</small></span></a>
        </nav>
    </x-ui.card>
</aside>
</div>
@endsection
