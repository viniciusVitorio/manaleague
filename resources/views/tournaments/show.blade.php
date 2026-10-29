@extends('layouts.app')
@section('content')
<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/competition.css') }}">
@php
$statusLabel=match($tournament->status){'setup'=>'Preparação','league'=>'Liga em andamento','finals'=>'Finais','finished'=>'Encerrado',default=>$tournament->status};
$leaguePending=$tournament->matches->where('stage','league')->where('status','pending')->count();
@endphp
<header class="arena-header"><div><a class="back-link" href="{{ route('tournaments.index') }}">← Todas as arenas</a><div class="title-line"><span class="format-badge">{{ $tournament->format }}</span><span class="status status-{{ $tournament->status }}">{{ $statusLabel }}</span></div><h1>{{ $tournament->name }}</h1>@if($tournament->club_id && $tournament->club)<p class="tournament-club"><a href="{{ route('clubs.show', $tournament->club) }}">🛡️ {{ $tournament->club->name }}</a></p>@endif<p class="tournament-date">{{ $tournament->tournament_date?->format('d/m/Y') ?? 'Data a definir' }}</p>@include('tournaments.partials.rules-note')</div><div class="header-actions">@if($tournament->status === 'setup')<a class="btn btn-secondary" href="{{ route('tournaments.edit',$tournament) }}">Editar torneio</a>@endif<a class="btn btn-ghost" target="_blank" rel="noopener" href="{{ route('arena.public',$tournament->public_slug) }}">Ver página pública ↗</a>@can('update', $tournament)<a class="btn btn-ghost" href="#repetir">Repetir torneio</a>@endcan @if($tournament->club_id && $tournament->club && auth()->user()->can('manage', $tournament->club))<form method="POST" action="{{ route('clubs.tournaments.unlink', [$tournament->club, $tournament]) }}" data-confirm="Tirar este torneio do clube? Ele sai do ranking do clube e volta a ser só do criador.">@csrf @method('DELETE')<button class="btn btn-ghost">Desvincular do clube</button></form>@endif<form method="POST" action="{{ route('tournaments.destroy',$tournament) }}" onsubmit="return confirm('Excluir o torneio e todos os resultados?')">@csrf @method('DELETE')<button class="btn btn-danger">Excluir</button></form></div></header>

@include('tournaments.partials.competition-status')
@if(in_array($tournament->status, ['league', 'finals'], true))
@php($timerRunning = $tournament->roundTimerRunning())
<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/live.css') }}"><script defer src="{{ \App\Support\VersionedAsset::url('js/round-timer.js') }}"></script>
<section class="card section-card round-timer-card" aria-labelledby="round-timer-title">
    <div class="section-heading"><div><span class="eyebrow">Dia de evento</span><h2 id="round-timer-title">Tempo da rodada</h2></div><x-round-timer :ends-at="$timerRunning ? $tournament->round_ends_at : null" /></div>
    <div class="round-timer-actions">
        <form method="POST" action="{{ route('tournaments.timer.start', $tournament) }}" class="round-timer-form">@csrf
            <label>Minutos<input type="number" name="minutes" min="5" max="180" value="{{ old('minutes', $tournament->round_minutes ?? \App\Http\Controllers\EventDayController::DEFAULT_ROUND_MINUTES) }}" required></label>
            <button class="btn btn-secondary">{{ $timerRunning ? 'Reiniciar' : 'Iniciar cronômetro' }}</button>
        </form>
        @if($timerRunning)<form method="POST" action="{{ route('tournaments.timer.stop', $tournament) }}">@csrf @method('DELETE')<button class="btn btn-ghost">Parar</button></form>@endif
    </div>
    <p class="muted small-note">O tempo aparece no placar público, no Modo TV e em Minhas partidas. Ao avançar a rodada, o cronômetro zera.</p>
</section>
@endif
@include('partials.podium')
@include('partials.retrospective')
@include('tournaments.partials.my-entry')
@include('tournaments.partials.registration-qr')

<section class="card section-card"><div class="section-heading"><div><span class="eyebrow">Ranking ao vivo</span><h2>Classificação</h2></div>@if($tournament->status==='league' && $tournament->system==='round_robin')<form method="POST" action="{{ route('tournaments.finals',$tournament) }}">@csrf<button class="btn btn-gold" @disabled($leaguePending>0)>Gerar final + 3º lugar</button></form>@endif</div>@include('partials.standings')</section>

@if($tournament->status==='setup')
<section class="card share-card"><div><span class="eyebrow">Convite aberto</span><h2>Link de inscrição</h2><p>Novos jogadores entram exclusivamente por este link.</p></div><div class="share-actions"><input id="invite-url" readonly value="{{ route('tournaments.invite.show',$tournament->invite_token) }}"><button type="button" class="btn btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById('invite-url').value);this.textContent='Copiado'">Copiar link</button></div></section>
<div class="two-columns"><section class="card section-card"><span class="eyebrow">Inscrições</span><h2>Jogadores <span class="count">{{ $tournament->players->count() }}</span></h2>
@php($present = $tournament->players->whereNotNull('checked_in_at')->count())
<div class="check-in-summary"><p class="muted">Check-in: <strong>{{ $present }} de {{ $tournament->players->count() }}</strong> com presença confirmada. Os jogadores confirmam pela página pública; você também pode marcar aqui.</p>@if($present > 0 && $present < $tournament->players->count())<form method="POST" action="{{ route('tournaments.absent.destroy',$tournament) }}" data-confirm="Remover {{ $tournament->players->count() - $present }} {{ $tournament->players->count() - $present === 1 ? 'inscrito sem check-in' : 'inscritos sem check-in' }}? Eles saem do torneio.">@csrf @method('DELETE')<button class="btn btn-small btn-ghost">Remover ausentes</button></form>@endif</div><div class="player-list">
@forelse($tournament->players as $player)
<div class="player-row"><div><strong>@if($player->profile)<a href="{{ route('profiles.show',$player->profile) }}">{{ $player->profile->avatar ?: '◉' }} {{ $player->name }}</a>@else{{ $player->name }}@endif</strong><small>{{ $player->deck_name ?: 'Deck surpresa' }} @if($player->deck_colors) · {{ $player->deck_colors }} @endif</small>@if(isset($bannedDecks[$player->deck_id]))<span class="ban-tag" title="O deck tem cartas banidas no {{ $tournament->format }}. A presença só pode ser marcada depois que a pessoa trocar as cartas ou o deck.">Deck inválido</span>@endif</div><div class="player-actions"><form method="POST" action="{{ route('tournaments.players.check-in',[$tournament,$player]) }}">@csrf @method('PATCH')@if($player->checked_in_at)<button class="btn btn-small btn-secondary check-in-on" aria-label="Desmarcar presença de {{ $player->name }}">✓ Presente</button>@else<button class="btn btn-small btn-ghost" aria-label="Marcar presença de {{ $player->name }}">Marcar presença</button>@endif</form><form method="POST" action="{{ route('players.destroy',[$tournament,$player]) }}">@csrf @method('DELETE')<button class="icon-button" aria-label="Remover jogador">×</button></form></div></div>
@empty<p class="muted">A mesa ainda está vazia. Envie o link para a galera.</p>@endforelse
</div><div class="friend-picker"><span class="eyebrow">Amigos salvos</span>
@forelse($friends as $friend)<form method="POST" action="{{ route('tournaments.friends.store',[$tournament,$friend]) }}">@csrf<div><strong><x-profile-link :of="$friend">{{ $friend->avatar ?: '★' }} {{ $friend->nickname }}</x-profile-link></strong><small>{{ $friend->preferred_deck_name ?: 'Deck não informado' }}</small></div><button class="btn btn-small btn-secondary">Adicionar</button></form>
@empty<p class="muted">Marque um perfil como amigo para adicioná-lo rapidamente em próximos campeonatos.</p>@endforelse
</div></section><aside class="card start-card"><span class="eyebrow">Próximo passo</span><h2>Gerar confrontos</h2><p>Com 4 ou mais jogadores, os confrontos seguem o sistema escolhido. Todos contra todos gera a liga inteira; os demais avançam rodada a rodada. Decks são congelados no início.</p><form method="POST" action="{{ route('tournaments.start',$tournament) }}">@csrf<button class="btn btn-primary btn-block" @disabled($tournament->players->count()<4)>Começar torneio</button></form><small>{{ max(0,4-$tournament->players->count())>0?'Faltam '.max(0,4-$tournament->players->count()).' jogadores.':'Tudo pronto para começar.' }}</small></aside></div>
@endif

@if($rounds->isNotEmpty())
<section class="rounds-section"><div class="section-heading"><div><span class="eyebrow">Rodadas</span><h2>Confrontos</h2></div><span class="muted">{{ $leaguePending }} partidas pendentes na liga</span></div>
@foreach($rounds as $roundNumber=>$matches)
<details class="round card" id="round-{{ $roundNumber }}" {{ $loop->first||$matches->contains('status','pending')?'open':'' }}><summary><span>Rodada {{ $roundNumber }}</span><span>{{ $matches->where('status','pending')->count() }} pendentes · {{ $matches->count() }} mesas</span></summary><div class="match-grid">
@foreach($matches as $match)@include('tournaments.partials.competition-match')@endforeach</div></details>
@endforeach</section>
@endif

@can('update', $tournament)
@php($repeat = $tournament->nextEditionDefaults())
<section class="card section-card repeat-card" id="repetir" aria-labelledby="repeat-title">
    <div class="section-heading"><div><span class="eyebrow">Liga toda semana?</span><h2 id="repeat-title">Repetir torneio</h2></div></div>
    <p class="muted">Cria um torneio novo com o mesmo formato, sistema e regras{{ $tournament->club_id ? ', no mesmo clube' : '' }}. Ele começa em preparação, com links de inscrição novos.</p>
    <form method="POST" action="{{ route('tournaments.repeat', $tournament) }}" class="repeat-form">@csrf
        <label>Nome<input name="name" value="{{ old('name', $repeat['name']) }}" maxlength="120" required></label>
        <label>Data<input type="date" name="tournament_date" value="{{ old('tournament_date', $repeat['date']) }}" required></label>
        <input type="hidden" name="copy_players" value="0">
        <label class="check-label"><input type="checkbox" name="copy_players" value="1" @checked(old('copy_players', true))> Trazer os mesmos {{ $tournament->players->count() }} jogadores (com os decks)</label>
        <button class="btn btn-secondary">Criar próximo torneio</button>
    </form>
</section>
@endcan
@endsection
