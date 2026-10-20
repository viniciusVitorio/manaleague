@extends('layouts.app')
@section('content')
@include('ranked.partials.head')
<header class="ranked-hero"><div><span class="eyebrow">ELO / PDL · partidas presenciais</span><h1>Ranked</h1><p class="muted">Cada formato tem seu próprio rating por temporada. Só partidas ranqueadas aceitas pelos dois e com placar confirmado mudam pontos.</p></div>
<div class="ranked-actions"><a class="btn btn-primary" href="#procurar">Procurar partida</a><a class="btn btn-secondary" href="{{ route('challenges.index', ['ranked' => 1]) }}">Desafiar amigo</a></div></header>

@if($active->isEmpty())
<section class="card section-card ranked-empty"><h2>Nenhuma temporada em andamento</h2><p>Quando a moderação abrir uma temporada, a fila e os desafios ranqueados aparecem aqui.@if($seasons->where('status','scheduled')->isNotEmpty()) Próxima: <strong>{{ $seasons->where('status','scheduled')->first()->name }}</strong> em {{ $seasons->where('status','scheduled')->first()->starts_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}.@endif</p></section>
@endif

<div class="ranked-grid">
<section class="card section-card"><h2>Minhas patentes</h2>
@forelse($ratings as $rating)
@php($t = $tiers->resolve($rating->rating))
<article class="rating-card tier-{{ $t['key'] }}"><div class="rating-head">@include('ranked.partials.tier', ['rating' => $rating->rating])<span class="muted">{{ $rating->format }} · {{ $rating->season->name }}</span></div>
<div class="rating-value"><strong>{{ $rating->rating }}</strong> ELO @if($rating->isProvisional())<span class="provisional" title="Primeiras {{ config('ranked.defaults.provisional_games') }} partidas">Provisório</span>@endif</div>
@if($t['pdl_max'])<div class="pdl" role="progressbar" aria-label="Progresso na divisão" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $t['pdl'] }}"><span style="width: {{ $t['pdl'] }}%"></span></div><small>{{ $t['pdl'] }}/100 PDL na divisão</small>@else<small>{{ $t['pdl'] }} PDL acima de Mestre</small>@endif
<p class="muted">{{ $rating->wins }}V {{ $rating->draws }}E {{ $rating->losses }}D · pico {{ $rating->peak_rating }}</p></article>
@empty<p>Você ainda não jogou ranqueado. Todos começam com <strong>1000 ELO (Prata II)</strong> em cada formato.</p>@endforelse
<p><a href="{{ route('ranked.players.show', $profile) }}">Meu histórico completo →</a></p>
</section>

<section class="card section-card" id="procurar"><h2>Procurar partida presencial</h2>
<div id="queue-panel" data-status-url="{{ route('ranked.queue.status') }}" data-heartbeat-url="{{ route('ranked.queue.heartbeat') }}" data-state="{{ $queue['state'] }}" aria-live="polite">
@if($queue['state'] === 'matched')
<div class="queue-state"><p><strong>Partida criada!</strong> Vocês dois aceitaram. Combinem a mesa, joguem e enviem o placar.</p><a class="btn btn-primary" href="{{ $queue['match_url'] }}">Abrir partida ranqueada</a></div>
@elseif(in_array($queue['state'], ['searching', 'proposal']))
<div class="queue-state"><p><strong data-queue-title>{{ $queue['state'] === 'proposal' ? 'Oponente encontrado' : 'Buscando' }}</strong> · {{ $queue['format'] }}@if($queue['club']) · {{ $queue['club'] }}@endif</p>
<p class="muted" data-queue-detail>Esperando há {{ $queue['waited_minutes'] }} min · aceitando diferença de até {{ $queue['window'] }} ELO · {{ $queue['queue_size'] }} {{ $queue['queue_size'] === 1 ? 'pessoa' : 'pessoas' }} nesta fila (contando você).</p>
<div data-proposal @if($queue['state'] !== 'proposal') hidden @endif>
@if($queue['state'] === 'proposal')<p>Contra <strong>{{ $queue['opponent'] }}</strong> ({{ $queue['opponent_rating'] }} ELO, diferença {{ $queue['gap'] }})@if($queue['session_note']) · “{{ $queue['session_note'] }}”@endif.</p>@if($queue['wide_gap'])<p class="alert alert-danger">Diferença alta de rating: vencer vale muito, perder custa pouco (e vice-versa).</p>@endif
@if($queue['i_accepted'])<p>Você aceitou. Aguardando o adversário…</p>@else
<form method="POST" action="{{ route('ranked.proposals.accept', $queue['proposal_id']) }}" class="queue-inline">@csrf<button class="btn btn-primary">Aceitar e jogar</button></form>
<form method="POST" action="{{ route('ranked.proposals.decline', $queue['proposal_id']) }}" class="queue-inline">@csrf<button class="btn btn-ghost">Recusar</button></form>@endif @endif
</div>
<form method="POST" action="{{ route('ranked.queue.leave') }}">@csrf @method('DELETE')<button class="btn btn-ghost btn-small">Sair da fila</button></form>
<noscript><p>Sem JavaScript a página não confirma sua presença: recarregue a cada poucos minutos ou a entrada expira.</p></noscript></div>
@else
@if($queue['state'] === 'expired')<p class="alert alert-danger">Sua última busca expirou por falta de confirmação de presença.</p>@endif
@if($activeFormats->isEmpty())<p>A fila abre quando houver temporada ativa.</p>@else
<form method="POST" action="{{ route('ranked.queue.join') }}" class="queue-form">@csrf
<label>Formato<select name="format" required>@foreach($activeFormats as $f)<option @selected(old('format') === $f)>{{ $f }}</option>@endforeach</select></label>
<label>Onde<select name="club_id"><option value="">Comunidade geral</option>@foreach($clubs as $club)<option value="{{ $club->id }}">{{ $club->name }}</option>@endforeach</select><small>Use um clube para casar só com quem joga no mesmo lugar.</small></label>
<label>Sessão (opcional)<input name="session_note" maxlength="120" placeholder="Ex.: loja do centro, hoje até 22h" value="{{ old('session_note') }}"><small>Não informe endereço exato.</small></label>
<label class="check-row"><input type="checkbox" name="available" value="1" required> Posso jogar presencialmente agora</label>
<button class="btn btn-primary">Entrar na fila</button></form>
<p class="muted">A fila só junta quem está disponível de verdade. Se ninguém estiver buscando, você verá apenas “Buscando”.</p>@endif
@endif
</div></section>
</div>

@if($open->isNotEmpty())
<section class="card section-card"><h2>Partidas em aberto</h2>
@foreach($open as $m)<a class="history-row" href="{{ route('ranked.matches.show', $m) }}"><div><strong>{{ $m->playerOne->nickname }} × {{ $m->playerTwo->nickname }}</strong><small>{{ $m->format }} · MD{{ $m->best_of }} · {{ $m->statusLabel() }}</small></div><span>Abrir →</span></a>@endforeach
</section>
@endif

<section class="card section-card"><h2>Últimas variações</h2>
@forelse($recent as $tx)
<div class="tx-row"><span class="delta {{ $tx->delta >= 0 ? 'up' : 'down' }}">{{ $tx->delta >= 0 ? '+' : '' }}{{ $tx->delta }}</span><div>@if($tx->event_type === 'correction')<strong>Correção da moderação</strong><small>{{ $tx->note }}</small>@else<strong>{{ $tx->rankedMatch?->playerOne?->nickname }} {{ $tx->rankedMatch?->score_one }}×{{ $tx->rankedMatch?->score_two }} {{ $tx->rankedMatch?->playerTwo?->nickname }}</strong><small>{{ $tx->format }} · {{ $tx->rating_before }} → {{ $tx->rating_after }} · esperado {{ number_format($tx->expected_score * 100, 0) }}% · K {{ $tx->k_factor }}</small>@endif</div><time datetime="{{ $tx->effective_at->toIso8601String() }}">{{ $tx->effective_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</time></div>
@empty<p class="muted">Sem partidas ranqueadas confirmadas ainda.</p>@endforelse
</section>

<section class="card section-card"><h2>Privacidade no ranking</h2>
<form method="POST" action="{{ route('ranked.privacy') }}">@csrf @method('PATCH')<input type="hidden" name="ranked_public" value="0"><label class="check-row"><input type="checkbox" name="ranked_public" value="1" @checked($profile->ranked_public) data-autosubmit> Mostrar meu apelido no ranking e no meu histórico ranqueado</label><noscript><button class="btn btn-small">Salvar</button></noscript></form>
<p class="muted">Desmarcado, você continua pontuando, mas aparece como “Jogador oculto” e só você vê seu histórico.</p></section>

@if($seasons->isNotEmpty())<section class="card section-card"><h2>Temporadas</h2>@foreach($seasons as $s)<a class="history-row" href="{{ route('ranked.seasons.show', $s) }}"><div><strong>{{ $s->name }}</strong><small>{{ $s->statusLabel() }} · {{ $s->starts_at->timezone('America/Sao_Paulo')->format('d/m/Y') }} – {{ $s->ends_at->timezone('America/Sao_Paulo')->format('d/m/Y') }} · {{ $s->formats->where('enabled', true)->pluck('format')->join(', ') }}@if($s->club) · {{ $s->club->name }}@endif</small></div><span>Ver →</span></a>@endforeach</section>@endif
@endsection
