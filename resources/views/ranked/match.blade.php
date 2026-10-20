@extends('layouts.app')
@section('content')
@include('ranked.partials.head')
@php($me = auth()->user())
<a class="back-link" href="{{ route('profiles.index') }}">← Meu perfil</a>
<header><span class="eyebrow">Partida ranqueada #{{ $match->id }} · {{ $match->format }} · MD{{ $match->best_of }} · {{ $match->season->name }}</span><h1>{{ $match->playerOne->nickname }} × {{ $match->playerTwo->nickname }}</h1><p><span class="status-pill status-{{ $match->status }}">{{ $match->statusLabel() }}</span> · K {{ $match->k_factor }} · origem: {{ $match->source_type === 'challenge' ? 'desafio' : 'fila' }}</p></header>

@if($match->status === 'confirmed' || $match->status === 'invalidated')
<section class="card section-card"><h2>{{ $match->score_one }} × {{ $match->score_two }}</h2>
@foreach($match->transactions->where('event_type', 'match') as $tx)<p><strong>{{ $tx->profile->nickname }}</strong>: {{ $tx->rating_before }} → {{ $tx->rating_after }} <span class="delta {{ $tx->delta >= 0 ? 'up' : 'down' }}">({{ $tx->delta >= 0 ? '+' : '' }}{{ $tx->delta }})</span> · esperado {{ number_format($tx->expected_score * 100, 1) }}% @if($tx->floor_applied)<em>· piso 0 aplicado</em>@endif</p>@endforeach
@if($match->status === 'invalidated')<div class="alert alert-danger">Invalidada em {{ $match->invalidated_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}: {{ $match->invalidation_reason }}. Os ratings foram recalculados com lançamentos de correção.</div>@endif
@foreach($match->transactions->where('event_type', 'correction') as $tx)<p class="muted">Correção: {{ $tx->profile->nickname }} {{ $tx->rating_before }} → {{ $tx->rating_after }} ({{ $tx->delta >= 0 ? '+' : '' }}{{ $tx->delta }})</p>@endforeach
@can('invalidate', $match)@if($match->status === 'confirmed' && in_array($match->season->status, ['active', 'closing']))
<details class="mod-box"><summary>Invalidar resultado (admin)</summary><form method="POST" action="{{ route('ranked.matches.invalidate', $match) }}">@csrf<label>Motivo (fica no histórico)<textarea name="reason" minlength="3" maxlength="500" required></textarea></label><button class="btn btn-danger" data-confirm="Invalidar e recalcular os ratings do formato?">Invalidar e recalcular</button></form></details>@endif @endcan
</section>
@elseif($match->status === 'cancelled')
<section class="card section-card"><p>Partida cancelada. Nada foi pontuado.</p></section>
@else
<section class="card section-card">
@if($match->status === 'reported' && $pending)
<h2>Placar enviado: {{ $match->score_one }} × {{ $match->score_two }}</h2><p class="muted">Enviado por {{ $pending->submitter->name === $me->name ? 'você' : 'adversário' }} em {{ $pending->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}. Ordem: {{ $match->playerOne->nickname }} / {{ $match->playerTwo->nickname }}.</p>
@if($match->involvesUser($me) && $pending->submitted_by !== $me->id)
<form method="POST" action="{{ route('ranked.matches.confirm', $match) }}">@csrf<p>Confirmar é definitivo e atualiza o ranking.</p><button class="btn btn-primary">Confirmar placar</button></form>
<form method="POST" action="{{ route('ranked.matches.dispute', $match) }}">@csrf<label>Motivo da contestação<textarea name="reason" minlength="3" maxlength="1000" required></textarea></label><button class="btn btn-secondary">Contestar</button></form>
@elseif($match->involvesUser($me))<p>Aguardando o adversário confirmar ou contestar.</p>@endif
@else
@if($match->status === 'disputed')<div class="alert alert-danger"><strong>Em disputa.</strong> Nenhum ponto muda até a resolução. Vocês podem enviar um placar corrigido (o adversário confirma de novo) ou aguardar a moderação.@php($d = $match->submissions->where('status', 'disputed')->sortByDesc('id')->first())@if($d)<p>Motivo: {{ $d->dispute_reason }}</p>@endif</div>@endif
@if($match->involvesUser($me))
<h2>Informar resultado</h2>
<form method="POST" action="{{ route('ranked.matches.results', $match) }}" class="score-entry">@csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<label>Games de {{ $match->playerOne->nickname }}<input type="number" inputmode="numeric" name="score_one" min="0" max="{{ $match->winsNeeded() }}" required></label>
<label>Games de {{ $match->playerTwo->nickname }}<input type="number" inputmode="numeric" name="score_two" min="0" max="{{ $match->winsNeeded() }}" required></label>
<button class="btn btn-primary">Enviar para confirmação</button></form>
@if($match->status === 'pending')<form method="POST" action="{{ route('ranked.matches.cancel', $match) }}">@csrf<button class="btn btn-ghost btn-small" data-confirm="Cancelar a partida? Nada será pontuado.">Cancelar partida</button></form>@endif
@endif
@endif
@can('resolve', $match)@if(in_array($match->status, ['reported', 'disputed']))
<details class="mod-box" open><summary>Resolver como moderação</summary><form method="POST" action="{{ route('ranked.matches.resolve', $match) }}">@csrf
<label>Games de {{ $match->playerOne->nickname }}<input type="number" name="score_one" min="0" max="{{ $match->winsNeeded() }}" value="{{ $match->score_one }}" required></label>
<label>Games de {{ $match->playerTwo->nickname }}<input type="number" name="score_two" min="0" max="{{ $match->winsNeeded() }}" value="{{ $match->score_two }}" required></label>
<label>Justificativa (fica no histórico)<textarea name="reason" minlength="3" maxlength="1000" required></textarea></label><button class="btn btn-primary">Definir placar e aplicar ELO</button></form></details>@endif @endcan
</section>
@endif

<section class="card section-card"><h2>Auditoria</h2><ol class="audit-list">@foreach($match->submissions->sortBy('id') as $s)<li>{{ $s->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }} · {{ $s->submitter->name }} enviou {{ $s->score_one }}×{{ $s->score_two }} · {{ ['pending' => 'aguardando', 'confirmed' => 'confirmado', 'disputed' => 'contestado', 'resolved' => 'resolvido pela moderação', 'superseded' => 'substituído'][$s->status] ?? $s->status }}@if($s->dispute_reason) · “{{ $s->dispute_reason }}”@endif @if($s->resolution_reason) · decisão: “{{ $s->resolution_reason }}”@endif</li>@endforeach @if($match->submissions->isEmpty())<li>Partida criada em {{ $match->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}; nenhum placar enviado.</li>@endif</ol></section>
@endsection
