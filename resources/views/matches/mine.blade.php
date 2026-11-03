@extends('layouts.app')
{{-- Tela ao vivo: atualiza sozinha quando algo muda (live-refresh.js). --}}
@section('live-scope', 'me')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/live.css') }}"><script defer src="{{ \App\Support\VersionedAsset::url('js/round-timer.js') }}"></script>@endpush
@section('content')
<header class="page-head">
    <div>
        <span class="eyebrow">Torneios, desafios e ranked</span>
        <h1>Minhas partidas</h1>
        <p>
            @if($total['played'])
                {{ $total['played'] }} {{ $total['played'] === 1 ? 'partida encerrada' : 'partidas encerradas' }} · campanha <b class="mm-record" title="Vitórias–Derrotas–Empates">{{ $total['label'] }}</b><span class="sr-only"> ({{ $total['w'] }} {{ $total['w'] === 1 ? 'vitória' : 'vitórias' }}, {{ $total['l'] }} {{ $total['l'] === 1 ? 'derrota' : 'derrotas' }}, {{ $total['d'] }} {{ $total['d'] === 1 ? 'empate' : 'empates' }})</span>
            @else
                Tudo o que você joga: torneios, desafios entre amigos e ranked. O placar vale quando o adversário confirma.
            @endif
        </p>
    </div>
    <div class="page-head-actions">
        <a class="ui-btn ui-btn-outline" href="{{ route('challenges.create') }}"><x-icon name="swords" />Desafiar amigo</a>
    </div>
</header>

@include('partials.confirm-all')

<nav class="mm-tabs" aria-label="Tipo de partida">
    @foreach($filters as $key => $name)
        <a class="mm-tab @if($filter === $key) is-active @endif" href="{{ route('matches.mine', $key === 'todas' ? [] : ['tipo' => $key]) }}" @if($filter === $key) aria-current="page" @endif>{{ $name }} <span class="mm-tab-count">{{ $counts[$key] }}</span></a>
    @endforeach
</nav>

@if($open->isEmpty() && $history->isEmpty())
    <x-ui.card>
        @switch($filter)
            @case('desafios')
                <x-ui.empty icon="swords" title="Nenhum desafio ainda." text="Chame um amigo para uma série casual ou valendo ranque." :href="route('challenges.create')" action="Desafiar amigo" />
                @break
            @case('ranked')
                <x-ui.empty icon="medal" title="Nenhuma partida ranqueada ainda." text="Entre na fila do Ranked ou mande um desafio ranqueado para um amigo." :href="route('challenges.create', ['ranked' => 1])" action="Desafio ranqueado" />
                @break
            @default
                <x-ui.empty icon="trophy" title="Nenhuma partida vinculada à sua conta." text="Entre num torneio pelo link de convite do organizador ou desafie um amigo. As partidas aparecem aqui." :href="route('challenges.create')" action="Desafiar amigo" />
        @endswitch
    </x-ui.card>
@else
    @if($open->isNotEmpty())
        <x-ui.card title="Em andamento" icon="clock" :meta="$open->count().' '.($open->count() === 1 ? 'partida' : 'partidas')" class="mm-section">
            @foreach($open as $row)
                @if($row['kind'] === 'tournament')
                    @include('matches.partials.open-match', ['row' => $row])
                @else
                    <div class="ui-list mm-list">@include('matches.partials.link-row', ['row' => $row])</div>
                @endif
            @endforeach
        </x-ui.card>
    @endif

    @if($filter === 'torneios')
        @foreach($groups as $tournamentId => $rows)
            @php($t = $rows->first()['tournament'])
            @php($rec = $records->get($tournamentId))
            <x-ui.card :title="$t->name" icon="trophy" class="mm-section"
                :meta="collect([$t->tournament_date?->format('d/m/Y'), $rec && $rec['played'] ? 'campanha '.$rec['label'] : null])->filter()->implode(' · ')"
                :href="$t->public_slug ? route('arena.public', $t->public_slug) : null" link-label="Placar">
                <div class="ui-list mm-list">
                    @foreach($rows as $row)
                        @include('matches.partials.history-row', ['row' => $row])
                    @endforeach
                </div>
            </x-ui.card>
        @endforeach
    @elseif($history->isNotEmpty())
        <x-ui.card title="Histórico" icon="calendar" :meta="$history->total().' '.($history->total() === 1 ? 'partida' : 'partidas')" class="mm-section">
            <div class="ui-list mm-list">
                @foreach($history as $row)
                    @if($row['kind'] === 'tournament')
                        @include('matches.partials.history-row', ['row' => $row, 'withTournament' => true])
                    @else
                        @include('matches.partials.link-row', ['row' => $row])
                    @endif
                @endforeach
            </div>
        </x-ui.card>
    @endif

    {{ $history->links() }}
@endif
@endsection
