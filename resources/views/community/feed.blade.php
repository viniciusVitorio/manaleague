@extends('layouts.app')
@section('content')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/hub.css') }}"><link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/ranked.css') }}">@endpush
@php($icons = ['tournament' => 'trophy', 'match' => 'target', 'challenge' => 'swords', 'ranked' => 'chart', 'club' => 'shield'])
<header class="page-head">
    <div><h1>Comunidade</h1><p>O que está rolando com seus amigos, clubes e torneios.</p></div>
    <div class="hub-actions"><a class="ui-btn ui-btn-outline" href="{{ route('friends.index') }}"><x-icon name="user-plus" />Amigos</a><a class="ui-btn ui-btn-primary" href="{{ route('challenges.index') }}"><x-icon name="swords" />Desafiar</a></div>
</header>

<div class="hub-grid">
<div class="hub-main">
    <x-ui.card title="Atividades" icon="activity">
        <nav class="chips" aria-label="Filtrar atividades">
            @foreach($filters as $key => $label)<a href="{{ route('feed.index', $key === 'all' ? [] : ['tipo' => $key]) }}" @if($filter === $key) aria-current="true" @endif>{{ $label }}</a>@endforeach
        </nav>
        @forelse($activities as $activity)
            @php($kind = strtok($activity->type, '.'))
            @php($actor = $activity->actor?->playerProfile?->nickname ?? $activity->actor?->name)
            <a class="feed-item" href="{{ $access->link(auth()->user(), $activity->subject_type, $activity->subject_id) }}">
                <span class="feed-icon feed-{{ $kind }}"><x-icon :name="$icons[$kind] ?? 'activity'" /></span>
                <span class="feed-body">
                    <span class="feed-title">{{ $activity->title }}</span>
                    <span class="feed-meta">@if($actor){{ $actor }} · @endif<time datetime="{{ $activity->created_at->toIso8601String() }}" title="{{ $activity->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}">{{ $activity->created_at->diffForHumans() }}</time></span>
                </span>
                @if($activity->type === 'tournament.finished' && ($activity->data['champion'] ?? null))<x-ui.badge tone="warning"><x-icon name="crown" size="12" />Campeão</x-ui.badge>@endif
            </a>
        @empty
            <x-ui.empty icon="activity" :title="$filter === 'all' ? 'Nada por aqui ainda.' : 'Nada nessa categoria.'" text="Torneios, resultados confirmados, desafios e novidades dos clubes aparecem aqui." :href="route('friends.index')" action="Encontrar amigos" />
        @endforelse
    </x-ui.card>
</div>

<aside class="hub-side">
    <x-ui.card title="Ranking entre amigos" icon="medal" :meta="$ranking->isNotEmpty() ? 'ELO da temporada' : null">
        @forelse($ranking as $format => $rows)
            <p class="ui-card-meta friends-rank-format">{{ $format }}</p>
            @foreach($rows as $i => $r)
                <div @class(['friends-rank-row', 'is-me' => $r['me']])>
                    <span class="num">{{ $i + 1 }}</span>
                    @include('ranked.partials.emblem', ['key' => $r['tier']['key'], 'size' => 22])
                    <span class="friends-rank-name">@if($r['me'])Você@else<a href="{{ route('profiles.show', $r['profile']) }}">{{ $r['profile']->nickname }}</a>@endif<small>{{ $r['tier']['division'] }} · {{ $r['record'] }}</small></span>
                    <strong class="num">{{ $r['rating'] }}</strong>
                </div>
            @endforeach
        @empty
            <p class="muted small">Quando você ou seus amigos jogarem ranqueado, a disputa aparece aqui.</p>
            <a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ route('challenges.index', ['ranked' => 1]) }}">Desafio ranqueado</a>
        @endforelse
    </x-ui.card>

    <x-ui.card title="Destaques da semana" icon="sparkles" meta="7 dias">
        @forelse($weekly as $i => $w)
            <div class="friends-rank-row"><span class="num">{{ $i + 1 }}</span><x-profile-link :of="$w['profile']" class="avatar-link" tabindex="-1" aria-hidden="true"><x-avatar :of="$w['profile']" class="ui-avatar" aria-hidden="true" /></x-profile-link><span class="friends-rank-name"><x-profile-link :of="$w['profile']">{{ $w['profile']->nickname }}</x-profile-link></span><strong class="num">{{ $w['wins'] }} {{ $w['wins'] === 1 ? 'vitória' : 'vitórias' }}</strong></div>
        @empty
            <p class="muted small">Ninguém do seu grupo jogou torneio nos últimos 7 dias.</p>
        @endforelse
    </x-ui.card>

    <x-ui.card title="Próximos torneios" icon="calendar">
        @forelse($upcoming as $t)
            <a class="ui-row" href="{{ route('tournaments.show', $t) }}"><span class="ui-row-main"><span class="ui-row-title">{{ $t->name }}</span><span class="ui-row-sub">{{ $t->format }} · {{ $t->tournament_date?->format('d/m') }}</span></span><x-icon name="chevron-right" /></a>
        @empty
            <p class="muted small">Nenhum torneio marcado nos seus clubes. <a href="{{ route('clubs.index') }}">Ver clubes</a></p>
        @endforelse
    </x-ui.card>

    @if($suggestions->isNotEmpty())
    <x-ui.card title="Talvez você conheça" icon="user-plus">
        @foreach($suggestions as $s)
            <div class="member-row"><x-profile-link :of="$s['profile']" class="avatar-link" tabindex="-1" aria-hidden="true"><x-avatar :of="$s['profile']" class="ui-avatar" aria-hidden="true" /></x-profile-link><span class="member-name"><a href="{{ route('profiles.show', $s['profile']) }}">{{ $s['profile']->nickname }}</a><small>{{ $s['mutual']->count() }} {{ $s['mutual']->count() === 1 ? 'amigo' : 'amigos' }} em comum</small></span>
            <form method="POST" action="{{ route('friends.store', $s['profile']->friend_code) }}">@csrf<button class="ui-btn ui-btn-outline ui-btn-sm">Adicionar</button></form></div>
        @endforeach
    </x-ui.card>
    @endif
</aside>
</div>
@endsection
