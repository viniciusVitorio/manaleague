@extends('layouts.app')
@section('content')
@include('community.nav')
<div class="dashboard-head"><div><h1>Meu painel</h1><p>Olá, {{ auth()->user()->name }}. @if($actionCount)Você tem <strong>{{ $actionCount }}</strong> {{ $actionCount === 1 ? 'pendência' : 'pendências' }} agora.@else Nada esperando por você agora.@endif</p></div>
<div class="community-stats"><div><strong>{{ $playing->count() }}</strong>torneios jogando</div><div><strong>{{ $unread }}</strong>{{ $unread === 1 ? 'não lida' : 'não lidas' }}</div></div></div>

@if($actionCount)
<section class="community-card dashboard-actions"><h2>Precisa de você</h2>
@if($queue['state'] === 'proposal')<article class="community-unread"><strong>Oponente encontrado na fila</strong> · {{ $queue['format'] }} contra {{ $queue['opponent'] }} <a class="btn btn-small btn-primary" href="{{ route('ranked.index') }}">Responder</a></article>@endif
@foreach($rankedNeedsMe as $rm)
<article class="community-unread"><strong>{{ $rm->status === 'reported' ? 'Confirmar placar ranqueado' : 'Enviar resultado ranqueado' }}</strong> · {{ $rm->format }}<br>{{ $rm->playerOne->nickname }} @if($rm->status === 'reported'){{ $rm->score_one }} × {{ $rm->score_two }}@else × @endif {{ $rm->playerTwo->nickname }} <a class="btn btn-small btn-primary" href="{{ route('ranked.matches.show', $rm) }}">Abrir</a></article>
@endforeach
@foreach($toConfirm as $submission)
<article class="community-unread"><strong>Confirmar placar</strong> · {{ $submission->gameMatch->tournament->name }} · R{{ $submission->gameMatch->round }}<br>{{ $submission->gameMatch->playerOne?->name }} {{ $submission->player_one_games }} × {{ $submission->player_two_games }} {{ $submission->gameMatch->playerTwo?->name }} <a class="btn btn-small btn-primary" href="{{ route('matches.mine') }}">Revisar</a></article>
@endforeach
@foreach($toReport as $match)
<article><strong>Enviar resultado</strong> · {{ $match->tournament->name }} · R{{ $match->round }}<br>{{ $match->playerOne?->name }} × {{ $match->playerTwo?->name }} <a class="btn btn-small btn-secondary" href="{{ route('matches.mine') }}">Enviar placar</a></article>
@endforeach
@foreach($challenges->filter(fn($c) => app(\App\Services\Community\DashboardService::class)->challengeNeedsMe($c, auth()->user())) as $challenge)
<article><strong>{{ $challenge->status === 'pending' ? 'Desafio recebido' : ($challenge->casualMatch?->status === 'reported' ? 'Confirmar placar do desafio' : 'Desafio em andamento') }}</strong> · {{ $challenge->challenger->nickname }} × {{ $challenge->opponent->nickname }} · {{ $challenge->format }} MD{{ $challenge->best_of }} <a class="btn btn-small btn-secondary" href="{{ route('challenges.show',$challenge) }}">Abrir</a></article>
@endforeach
@foreach($organizing as $tournament)@if($awaitingReview->get($tournament->id))
<article><strong>Resultados para revisar</strong> · {{ $tournament->name }} · {{ $awaitingReview->get($tournament->id) }} <a class="btn btn-small btn-secondary" href="{{ route('matches.submissions.queue',$tournament) }}">Abrir fila</a></article>
@endif @endforeach
</section>
@endif

<div class="community-grid">
@if(config('ranked.enabled'))<section class="community-card"><h2>Ranked</h2>@php($tierResolver = app(\App\Services\Ranked\RankTierResolver::class))@forelse($rankedRatings as $rr)<p><strong>{{ $rr->format }}</strong> · {{ $tierResolver->resolve($rr->rating)['division'] }} · {{ $rr->rating }} ELO</p>@empty<p>Você ainda não jogou ranqueado. Todos começam em Prata II (1000).</p>@endforelse @if($queue['state'] === 'searching')<p>Na fila de {{ $queue['format'] }} há {{ $queue['waited_minutes'] }} min.</p>@endif<p><a href="{{ route('ranked.index') }}">Procurar partida →</a></p></section>@endif
<section class="community-card"><h2>Jogando</h2>@forelse($playing as $tournament)<p><a href="{{ route('arena.public',$tournament->public_slug) }}">{{ $tournament->name }}</a> · {{ ['setup'=>'Inscrições','league'=>'Em andamento','finals'=>'Finais'][$tournament->status] ?? $tournament->status }}</p>@empty<p>Você não está inscrito em torneios ativos. Peça o link ou QR ao organizador.</p>@endforelse<p><a href="{{ route('matches.mine') }}">Minhas partidas →</a></p></section>
<section class="community-card"><h2>Meus torneios</h2>@forelse($organizing as $tournament)<p><a href="{{ route('tournaments.show',$tournament) }}">{{ $tournament->name }}</a> · {{ ['setup'=>'Preparação','league'=>'Em andamento','finals'=>'Finais','finished'=>'Encerrado'][$tournament->status] ?? $tournament->status }}@if(in_array($tournament->status,['league','finals'])) · {{ $tournament->pending_matches_count }} pendentes @endif</p>@empty<p>Nenhum torneio por enquanto.</p>@endforelse<p><a href="{{ route('tournaments.create') }}">Criar torneio →</a></p></section>
<section class="community-card"><h2>Notificações</h2><p>{{ $unread }} não lidas</p>@foreach($latestUnread as $notification)<p><a href="{{ $notification->url }}">{{ $notification->title }}</a></p>@endforeach<a href="{{ route('notifications.index') }}">Abrir notificações</a></section>
<section class="community-card"><h2>Desafios</h2>@forelse($challenges as $challenge)<p><a href="{{ route('challenges.show',$challenge) }}">{{ $challenge->challenger->nickname }} × {{ $challenge->opponent->nickname }}</a> · {{ $challenge->statusLabel() }}</p>@empty<p>Nenhum desafio aberto.</p>@endforelse<p><a href="{{ route('challenges.index') }}">Desafiar um amigo →</a></p></section>
<section class="community-card"><h2>Meus clubes</h2>@forelse($clubs as $club)<p><a href="{{ route('clubs.show',$club) }}">{{ $club->name }}</a></p>@empty<p>Você ainda não participa de clubes.</p>@endforelse<p><a href="{{ route('clubs.index') }}">Ver clubes →</a></p></section>
<section class="community-card"><h2>Atividade recente</h2>@forelse($activities as $activity)<p><a href="{{ app(\App\Services\Community\CommunityAccess::class)->link(auth()->user(),$activity->subject_type,$activity->subject_id) }}">{{ $activity->title }}</a> <time datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->format('d/m H:i') }}</time></p>@empty<p>Sem atividades visíveis ainda.</p>@endforelse<p><a href="{{ route('feed.index') }}">Ver tudo →</a></p></section>
</div>
@endsection
