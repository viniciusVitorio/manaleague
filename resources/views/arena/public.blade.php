@extends('layouts.app')
@php
    // Prévia do link no WhatsApp: formato, data, jogadores e líder (ou campeão).
    $leader = $ranking->first();
    $fire = fn (array $row) => $row['streak'] >= 6 ? ' ⚡' : ($row['streak'] >= 3 ? ' 🔥' : '');
    $highlight = $recap ? 'Campeão: '.$recap['champion']->displayName().' 🏆'
        : ($leader && $leader['played'] > 0 ? 'Líder: '.$leader['player']->displayName().' ('.$leader['points'].' pts)'.$fire($leader) : null);
    $title = $tournament->name.' · ManaLeague';
    $og = ['title' => '🏆 '.$tournament->name, 'description' => implode(' · ', array_filter([
        $tournament->format, $tournament->tournament_date?->format('d/m/Y'), $tournament->players->count().' jogadores', $highlight,
    ]))];
@endphp
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/live.css') }}"><script defer src="{{ \App\Support\VersionedAsset::url('js/live.js') }}"></script><script defer src="{{ \App\Support\VersionedAsset::url('js/round-timer.js') }}"></script>@endpush
@section('content')
<a class="btn btn-ghost" href="{{ route('arena.tv',$tournament->public_slug) }}">Modo TV</a>
@auth<a class="btn btn-ghost" href="{{ route('matches.mine') }}">Minhas partidas</a>@endauth
@include('arena.live-panel')
@php($statusLabel=match($tournament->status){'setup'=>'Preparação','league'=>'Liga em andamento','finals'=>'Finais','finished'=>'Encerrado',default=>$tournament->status})
<header class="public-hero"><span class="eyebrow">Placar oficial</span><div class="title-line"><span class="format-badge">{{ $tournament->format }}</span><span class="status status-{{ $tournament->status }}">{{ $statusLabel }}</span></div><h1>{{ $tournament->name }}</h1><p class="public-date">{{ $tournament->tournament_date?->format('d/m/Y') }}</p><p>Classificação e resultados atualizados pelo organizador.</p></header>
@include('partials.podium')
@include('partials.retrospective')
@include('tournaments.partials.my-entry')
<section class="card section-card"><div class="section-heading"><div><span class="eyebrow">Ranking ao vivo</span><h2>Classificação</h2></div></div>@include('partials.standings')</section>
@if($rounds->isNotEmpty())<section class="rounds-section"><div class="section-heading"><div><span class="eyebrow">Resultados</span><h2>Rodadas</h2></div></div>
@foreach($rounds as $roundNumber=>$matches)<details class="round card" {{ $loop->last?'open':'' }}><summary><span>Rodada {{ $roundNumber }}</span><span>{{ $matches->whereIn('status',['finished','bye'])->count() }}/{{ $matches->count() }} concluídas</span></summary><div class="match-grid">
@foreach($matches as $match)<article class="match-card {{ $match->stage!=='league'?'decisive':'' }}"><div class="match-stage">{{ match($match->stage){'final'=>'FINAL','third_place'=>'3º LUGAR',default=>$match->status==='bye'?'BYE':'LIGA'} }}</div><div class="versus"><span><x-profile-link :of="$match->playerOne">{{ $match->playerOne->displayName() }}</x-profile-link></span><b>{{ $match->player_one_games??'–' }} × {{ $match->player_two_games??'–' }}</b><span><x-profile-link :of="$match->playerTwo">{{ $match->playerTwo?->displayName()??'Folga' }}</x-profile-link></span></div></article>@endforeach
</div></details>@endforeach</section>@endif
@endsection
