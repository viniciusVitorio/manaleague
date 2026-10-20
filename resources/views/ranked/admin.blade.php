@extends('layouts.app')
@section('content')
@include('ranked.partials.head')
<header><span class="eyebrow">Moderação</span><h1>Ranked · administração</h1><p class="muted">Admins criam temporadas e resolvem disputas. O ELO nunca é editado diretamente.</p></header>
<section class="card section-card"><h2>Nova temporada</h2>
<form method="POST" action="{{ route('ranked.admin.seasons.store') }}" class="admin-form">@csrf
<label>Nome<input name="name" maxlength="120" required value="{{ old('name') }}"></label>
<label>Início (Brasília)<input type="datetime-local" name="starts_at" required value="{{ old('starts_at') }}"></label>
<label>Fim (Brasília)<input type="datetime-local" name="ends_at" required value="{{ old('ends_at') }}"></label>
<fieldset><legend>Formatos 1v1</legend>@foreach(config('ranked.formats') as $f)<label class="check-row"><input type="checkbox" name="formats[]" value="{{ $f }}" @checked(in_array($f, old('formats', ['Pauper'])))> {{ $f }}</label>@endforeach</fieldset>
<label>Escopo<select name="scope"><option value="global">Comunidade (global)</option><option value="club">Clube</option></select></label>
<label>Clube (se escopo clube)<select name="club_id"><option value="">—</option>@foreach($clubs as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
<label>Fator K<input type="number" name="k_factor" min="8" max="64" value="{{ old('k_factor', 32) }}" required></label>
<label>Limite por dupla em 24h<input type="number" name="pair_limit_24h" min="1" max="10" value="{{ old('pair_limit_24h', 3) }}" required></label>
<input type="hidden" name="allow_draws" value="0"><label class="check-row"><input type="checkbox" name="allow_draws" value="1" checked> Permitir empates</label>
<button class="btn btn-primary">Criar rascunho</button></form></section>

<section class="card section-card"><h2>Temporadas</h2>@forelse($seasons as $s)<div class="history-row"><div><strong>{{ $s->name }}</strong><small>{{ $s->statusLabel() }} · {{ $s->starts_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} – {{ $s->ends_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} · {{ $s->formats->pluck('format')->join(', ') }}@if($s->club) · {{ $s->club->name }}@endif</small></div><div>
@if($s->status === 'draft')<form method="POST" action="{{ route('ranked.admin.seasons.schedule', $s) }}">@csrf<button class="btn btn-small btn-primary">Publicar</button></form>@endif
@if($s->status === 'active')<form method="POST" action="{{ route('ranked.admin.seasons.close', $s) }}">@csrf<button class="btn btn-small btn-danger" data-confirm="Encerrar a temporada agora? Novas partidas deixam de pontuar.">Encerrar</button></form>@endif</div></div>@empty<p>Nenhuma temporada criada.</p>@endforelse</section>

<section class="card section-card"><h2>Placares em revisão</h2>@forelse($disputes as $m)<a class="history-row" href="{{ route('ranked.matches.show', $m) }}"><div><strong>#{{ $m->id }} · {{ $m->playerOne->nickname }} × {{ $m->playerTwo->nickname }}</strong><small>{{ $m->season->name }} · {{ $m->format }} · {{ $m->statusLabel() }} desde {{ $m->updated_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</small></div><span>Abrir →</span></a>@empty<p class="muted">Nada aguardando.</p>@endforelse</section>

<section class="card section-card"><h2>Alertas de revisão (anti-farming)</h2><p class="muted">Sinais para olhar com calma. Não são punição automática.</p>@forelse($flags as $f)<div class="history-row"><div><strong>{{ $f->profileA->nickname }} × {{ $f->profileB->nickname }}</strong><small>{{ $f->season->name }} · {{ $f->format }} · {{ $f->details }}</small></div><form method="POST" action="{{ route('ranked.admin.flags', $f) }}">@csrf @method('PATCH')<button class="btn btn-small btn-ghost" name="status" value="dismissed">Sem problema</button> <button class="btn btn-small btn-secondary" name="status" value="actioned">Tratado</button></form></div>@empty<p class="muted">Nenhum alerta aberto.</p>@endforelse</section>

<section class="card section-card"><h2>Suspensões</h2><form method="POST" action="{{ route('ranked.admin.suspend') }}" class="admin-form">@csrf<label>Código de amigo<input name="friend_code" maxlength="9" placeholder="ABCD-EFGH" required></label><label>Dias (0 remove)<input type="number" name="days" min="0" max="365" value="7" required></label><button class="btn btn-secondary">Aplicar</button></form>
@foreach($suspended as $p)<p>{{ $p->nickname }} · até {{ $p->ranked_suspended_until->timezone('America/Sao_Paulo')->format('d/m/Y') }}</p>@endforeach</section>
@endsection
