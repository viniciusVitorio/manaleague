@extends('layouts.app')
{{-- Tela ao vivo: atualiza sozinha quando algo muda (live-refresh.js). --}}
{{-- Página de quem joga e de quem acompanha: rodada atual, classificação e as outras rodadas, sem repetir a mesma lista. --}}
@section('live-scope', 'arena:'.$tournament->public_slug.(auth()->check() ? ',me' : ''))
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

    $inSetup = $tournament->status === 'setup';
    $running = in_array($tournament->status, ['league', 'finals'], true);
    $finished = $tournament->status === 'finished';
    $statusLabel = match ($tournament->status) { 'setup' => 'Inscrições abertas', 'league' => 'Em andamento', 'finals' => 'Finais', 'finished' => 'Encerrado', default => $tournament->status };
    $systemLabel = \App\Models\Tournament::SYSTEMS[$tournament->system] ?? $tournament->system;
    $playersCount = $tournament->players->count();
    // Mesma regra da página do organizador: a rodada atual é a primeira com partida sem resultado.
    $currentRound = $running ? $rounds->keys()->first(fn ($round) => $rounds[$round]->contains('status', 'pending')) : null;
    $currentMatches = $currentRound !== null ? $rounds[$currentRound] : collect();
    $isFinalsRound = $currentMatches->isNotEmpty() && $currentMatches->every(fn ($m) => in_array($m->stage, ['final', 'third_place'], true));
    $otherRounds = $currentRound !== null ? $rounds->filter(fn ($matches, $round) => $round !== $currentRound) : $rounds;
    $timerEndsAt = $tournament->roundTimerRunning() ? $tournament->round_ends_at : null;
@endphp
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/competition.css') }}"><link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/live.css') }}"><script defer src="{{ \App\Support\VersionedAsset::url('js/round-timer.js') }}"></script>@endpush
@section('content')
<header class="public-hero">
    <span class="eyebrow">{{ $running ? 'Placar ao vivo' : 'Placar oficial' }}</span>
    <div class="title-line"><span class="format-badge">{{ $tournament->format }}</span><span class="status status-{{ $tournament->status }}">{{ $statusLabel }}</span></div>
    <h1>{{ $tournament->name }}</h1>
    <p class="tourney-meta">
        <span>{{ $systemLabel }}</span>
        <span>{{ $tournament->tournament_date?->format('d/m/Y') ?? 'Data a definir' }}</span>
        <span>{{ $playersCount }} {{ $playersCount === 1 ? 'jogador' : 'jogadores' }}</span>
    </p>
    @include('tournaments.partials.rules-note')
    @if($running)<p class="public-live"><span class="public-live-dot" aria-hidden="true"></span>Atualiza sozinho a cada resultado</p>@endif
    <div class="public-actions">
        <a class="ui-btn ui-btn-outline ui-btn-sm" target="_blank" rel="noopener" href="{{ route('arena.tv', $tournament->public_slug) }}"><x-icon name="layout-dashboard" />Modo TV ↗</a>
        @auth<a class="ui-btn ui-btn-outline ui-btn-sm" href="{{ route('matches.mine') }}"><x-icon name="swords" />Minhas partidas</a>@endauth
    </div>
    <x-round-timer :ends-at="$timerEndsAt" class="public-timer" />
</header>

@if($finished)@include('tournaments.partials.champion-card')@endif
@include('tournaments.partials.my-entry')

{{-- EM ANDAMENTO: as mesas da rodada atual primeiro --}}
@if($currentRound !== null)
<section class="card section-card tourney-round" aria-labelledby="public-round-title">
    <div class="tourney-round-head">
        <div>
            <span class="eyebrow">Rodada atual</span>
            <h2 id="public-round-title">@if($isFinalsRound)Final e 3º lugar @else Rodada {{ $currentRound }}@endif</h2>
        </div>
    </div>
    <div class="match-grid tourney-matches">@foreach($currentMatches as $match)@include('tournaments.partials.competition-match', ['live' => true, 'table' => $loop->iteration])@endforeach</div>
</section>
@endif

@if($inSetup)
{{-- PREPARAÇÃO: só quem já está inscrito; a classificação aparece quando o torneio começar --}}
<section class="card section-card" aria-labelledby="public-players-title">
    <div class="section-heading"><div><span class="eyebrow">Inscrições</span><h2 id="public-players-title">Jogadores <span class="count">{{ $playersCount }}</span></h2></div></div>
    <div class="player-list">
        @forelse($tournament->players as $player)
            <div class="player-row"><div><strong><x-profile-link :of="$player">{{ $player->displayName() }}</x-profile-link></strong>@if($player->deck && auth()->user()?->can('view', $player->deck))<small><a class="player-link" href="{{ route('decks.show', $player->deck) }}">{{ $player->deck->name }}</a></small>@elseif($player->deckLabel())<small>{{ $player->deckLabel() }}</small>@endif</div>@if($player->checked_in_at)<span class="tourney-present">✓ Presente</span>@endif</div>
        @empty
            <p class="muted">Ninguém se inscreveu ainda.</p>
        @endforelse
    </div>
</section>
@else
<section class="card section-card" aria-labelledby="standings-title">
    <div class="section-heading"><div><h2 id="standings-title">{{ $finished ? 'Classificação final' : 'Classificação' }}</h2></div></div>
    @include('partials.standings')
</section>
@endif

@if($otherRounds->isNotEmpty())
    @include('tournaments.partials.round-list', ['rounds' => $otherRounds, 'currentRound' => $currentRound, 'title' => $currentRound !== null ? 'Outras rodadas' : 'Rodadas'])
@endif
@endsection
