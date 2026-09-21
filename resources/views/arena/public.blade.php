@extends('layouts.app')
@section('content')
@php($statusLabel = match($tournament->status) {'setup' => 'Preparação', 'league' => 'Liga em andamento', 'finals' => 'Finais', 'finished' => 'Encerrado', default => $tournament->status})
<header class="public-hero"><span class="eyebrow">Placar oficial</span><div class="title-line"><span class="format-badge">{{ $tournament->format }}</span><span class="status status-{{ $tournament->status }}">{{ $statusLabel }}</span></div><h1>{{ $tournament->name }}</h1><p class="public-date">{{ $tournament->tournament_date?->format('d/m/Y') }}</p><p>Classificação e resultados atualizados pelo organizador.</p></header>

@include('partials.podium')

<section class="card section-card"><div class="section-heading"><div><span class="eyebrow">Ranking ao vivo</span><h2>Classificação</h2></div></div>@include('partials.standings')</section>

@if($rounds->isNotEmpty())
<section class="rounds-section"><div class="section-heading"><div><span class="eyebrow">Resultados</span><h2>Rodadas</h2></div></div>
@foreach($rounds as $roundNumber => $matches)
<details class="round card" {{ $loop->last ? 'open' : '' }}><summary><span>Rodada {{ $roundNumber }}</span><span>{{ $matches->whereIn('status', ['finished','bye'])->count() }}/{{ $matches->count() }} concluídas</span></summary><div class="match-grid">
@foreach($matches as $match)
<article class="match-card {{ $match->stage !== 'league' ? 'decisive' : '' }}"><div class="match-stage">{{ match($match->stage) {'final' => 'FINAL', 'third_place' => '3º LUGAR', default => $match->status === 'bye' ? 'BYE' : 'LIGA'} }}</div><div class="versus"><span>{{ $match->playerOne->name }}</span><b>{{ $match->player_one_games ?? '–' }} × {{ $match->player_two_games ?? '–' }}</b><span>{{ $match->playerTwo?->name ?? 'Folga' }}</span></div></article>
@endforeach
</div></details>
@endforeach
</section>
@endif
@endsection
