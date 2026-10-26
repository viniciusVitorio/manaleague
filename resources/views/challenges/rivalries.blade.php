@extends('layouts.app')
@section('content')
<a class="back-link" href="{{ route('challenges.index') }}">← Desafios</a>
<header class="page-head">
    <div><h1>Rivalidades</h1><p>Seu retrospecto contra cada amigo nos desafios 1v1.</p></div>
    <a class="ui-btn ui-btn-primary" href="{{ route('challenges.index') }}"><x-icon name="swords" />Novo desafio</a>
</header>

<div class="ui-hint" style="margin-bottom:16px">
    <x-icon name="info" />
    <div>
        <p><strong>Como funcionam as rivalidades</strong></p>
        <ul>
            <li>Cada desafio 1v1 vira uma <strong>série</strong> (melhor de 1, 3 ou 5). Quem ganha mais games leva a série; o placar fica como vitórias–derrotas (–empates, se houver).</li>
            <li>Só contam séries com <strong>placar confirmado pelos dois</strong>. Convites recusados, expirados ou contestados não entram.</li>
            <li>Valem desafios casuais e ranqueados. Partidas de torneio ficam nas estatísticas do perfil, não aqui.</li>
            <li>A rivalidade é só um retrospecto: ela <strong>não mexe</strong> no seu ELO nem nas estatísticas de torneio (o ELO vem da própria partida ranqueada). Use <em>Revanche</em> para desafiar de novo.</li>
        </ul>
    </div>
</div>

<x-ui.card title="Seus rivais" icon="users" :meta="$rivals->count() ? $rivals->count().' '.($rivals->count() === 1 ? 'rival' : 'rivais') : null">
    @forelse($rivals as $rival)
        @php($pct = $rival['played'] ? round($rival['wins'] / $rival['played'] * 100) : 0)
        <div class="ui-row rival-row">
            <x-avatar :of="$rival['profile']" class="ui-avatar" aria-hidden="true" />
            <span class="ui-row-main"><span class="ui-row-title">{{ $rival['profile']->nickname }}</span><span class="ui-row-sub">{{ $rival['played'] }} {{ $rival['played'] === 1 ? 'série' : 'séries' }}@if($rival['ranked']) · {{ $rival['ranked'] }} ranqueada{{ $rival['ranked'] > 1 ? 's' : '' }}@endif · {{ $pct }}% de vitórias</span></span>
            <span class="rival-bar" aria-hidden="true"><span style="width: {{ $pct }}%"></span></span>
            <span class="rival-record" aria-label="{{ $rival['wins'] }} vitórias, {{ $rival['losses'] }} derrotas{{ $rival['draws'] ? ' e '.$rival['draws'].' empates' : '' }}"><strong>{{ $rival['wins'] }}</strong>–{{ $rival['losses'] }}@if($rival['draws'])–{{ $rival['draws'] }}@endif</span>
            <a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ route('challenges.index', ['opponent' => $rival['profile']->id]) }}">Revanche</a>
        </div>
    @empty
        <x-ui.empty icon="swords" title="Nenhuma rivalidade ainda." text="Desafie um amigo; quando os dois confirmarem o placar, a rivalidade aparece aqui." :href="route('challenges.index')" action="Desafiar" />
    @endforelse
</x-ui.card>
@endsection
