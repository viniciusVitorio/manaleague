@extends('layouts.app')
@section('content')
@php
$statusLabel=match($tournament->status){'setup'=>'Preparação','league'=>'Liga em andamento','finals'=>'Finais','finished'=>'Encerrado',default=>$tournament->status};
$leaguePending=$tournament->matches->where('stage','league')->where('status','pending')->count();
@endphp
<header class="arena-header"><div><a class="back-link" href="{{ route('tournaments.index') }}">← Todas as arenas</a><div class="title-line"><span class="format-badge">{{ $tournament->format }}</span><span class="status status-{{ $tournament->status }}">{{ $statusLabel }}</span></div><h1>{{ $tournament->name }}</h1><p class="tournament-date">{{ $tournament->tournament_date?->format('d/m/Y') ?? 'Data a definir' }}</p></div><div class="header-actions"><a class="btn btn-ghost" target="_blank" rel="noopener" href="{{ route('arena.public',$tournament->public_slug) }}">Ver página pública ↗</a><form method="POST" action="{{ route('tournaments.destroy',$tournament) }}" onsubmit="return confirm('Excluir o torneio e todos os resultados?')">@csrf @method('DELETE')<button class="btn btn-danger">Excluir</button></form></div></header>

@include('partials.podium')
@include('partials.retrospective')

<section class="card section-card"><div class="section-heading"><div><span class="eyebrow">Ranking ao vivo</span><h2>Classificação</h2></div>@if($tournament->status==='league')<form method="POST" action="{{ route('tournaments.finals',$tournament) }}">@csrf<button class="btn btn-gold" @disabled($leaguePending>0)>Gerar final + 3º lugar</button></form>@endif</div>@include('partials.standings')</section>

@if($tournament->status==='setup')
<section class="card share-card"><div><span class="eyebrow">Convite aberto</span><h2>Link de inscrição</h2><p>Novos jogadores entram exclusivamente por este link.</p></div><div class="share-actions"><input id="invite-url" readonly value="{{ route('tournaments.invite.show',$tournament->invite_token) }}"><button type="button" class="btn btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById('invite-url').value);this.textContent='Copiado'">Copiar link</button></div></section>
<div class="two-columns"><section class="card section-card"><span class="eyebrow">Inscrições</span><h2>Jogadores <span class="count">{{ $tournament->players->count() }}</span></h2><div class="player-list">
@forelse($tournament->players as $player)
<div class="player-row"><div><strong>@if($player->profile)<a href="{{ route('profiles.show',$player->profile) }}">{{ $player->profile->avatar ?: '◉' }} {{ $player->name }}</a>@else{{ $player->name }}@endif</strong><small>{{ $player->deck_name ?: 'Deck surpresa' }} @if($player->deck_colors) · {{ $player->deck_colors }} @endif</small></div><form method="POST" action="{{ route('players.destroy',[$tournament,$player]) }}">@csrf @method('DELETE')<button class="icon-button" aria-label="Remover jogador">×</button></form></div>
@empty<p class="muted">A mesa ainda está vazia. Envie o link para a galera.</p>@endforelse
</div><div class="friend-picker"><span class="eyebrow">Amigos salvos</span>
@forelse($friends as $friend)<form method="POST" action="{{ route('tournaments.friends.store',[$tournament,$friend]) }}">@csrf<div><strong>{{ $friend->avatar ?: '★' }} {{ $friend->nickname }}</strong><small>{{ $friend->preferred_deck_name ?: 'Deck não informado' }}</small></div><button class="btn btn-small btn-secondary">Adicionar</button></form>
@empty<p class="muted">Marque um perfil como amigo para adicioná-lo rapidamente em próximos campeonatos.</p>@endforelse
</div></section><aside class="card start-card"><span class="eyebrow">Próximo passo</span><h2>Gerar confrontos</h2><p>Com 4 ou mais jogadores, o algoritmo cria a liga completa. Número ímpar recebe BYE.</p><form method="POST" action="{{ route('tournaments.start',$tournament) }}">@csrf<button class="btn btn-primary btn-block" @disabled($tournament->players->count()<4)>Começar torneio</button></form><small>{{ max(0,4-$tournament->players->count())>0?'Faltam '.max(0,4-$tournament->players->count()).' jogadores.':'Tudo pronto para começar.' }}</small></aside></div>
@endif

@if($rounds->isNotEmpty())
<section class="rounds-section"><div class="section-heading"><div><span class="eyebrow">Rodadas</span><h2>Confrontos</h2></div><span class="muted">{{ $leaguePending }} partidas pendentes na liga</span></div>
@foreach($rounds as $roundNumber=>$matches)
<details class="round card" {{ $loop->first||$matches->contains('status','pending')?'open':'' }}><summary><span>Rodada {{ $roundNumber }}</span><span>{{ $matches->where('status','pending')->count() }} pendentes · {{ $matches->count() }} mesas</span></summary><div class="match-grid">
@foreach($matches as $match)<article class="match-card {{ $match->stage!=='league'?'decisive':'' }}"><div class="match-stage">{{ match($match->stage){'final'=>'FINAL','third_place'=>'3º LUGAR',default=>$match->status==='bye'?'BYE':'LIGA'} }}</div><div class="versus"><span>{{ $match->playerOne->name }}</span><b>{{ $match->player_one_games??'–' }} × {{ $match->player_two_games??'–' }}</b><span>{{ $match->playerTwo?->name??'Folga' }}</span></div>
@if($match->status!=='bye')<form class="score-form" method="POST" action="{{ route('matches.update',[$tournament,$match]) }}">@csrf @method('PUT')<input type="number" name="player_one_games" min="0" max="3" value="{{ $match->player_one_games??0 }}" aria-label="Games de {{ $match->playerOne->name }}"><span>×</span><input type="number" name="player_two_games" min="0" max="3" value="{{ $match->player_two_games??0 }}" aria-label="Games de {{ $match->playerTwo?->name }}"><button class="btn btn-small btn-secondary">{{ $match->status==='finished'?'Corrigir':'Salvar' }}</button></form>@else<p class="bye-note">Vitória automática por 2×0.</p>@endif
</article>@endforeach</div></details>
@endforeach</section>
@endif
@endsection
