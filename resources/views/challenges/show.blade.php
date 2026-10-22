@extends('layouts.app')
@section('content')
@php($match = $challenge->casualMatch)
<a class="back-link" href="{{ route('challenges.index') }}">← Desafios</a>
<header><span class="eyebrow">{{ $challenge->is_ranked ? 'Ranqueada' : 'Casual' }} · {{ $challenge->format }} · MD{{ $challenge->best_of }}</span><h1>{{ $challenge->challenger->nickname }} × {{ $challenge->opponent->nickname }}</h1><p>{{ $challenge->statusLabel() }}</p></header>
<section class="card section-card">
@if($challenge->status === 'pending')
<p>Convite válido até {{ $challenge->expires_at->format('d/m/Y H:i') }}.</p>
<form method="POST" action="{{ route('challenges.transition', $challenge) }}">@csrf
@if($challenge->opponent->account_user_id === auth()->id())<button class="btn btn-primary" name="action" value="accept">Aceitar</button> <button class="btn btn-secondary" name="action" value="decline">Recusar</button>
@else<button class="btn btn-secondary" name="action" value="cancel">Cancelar convite</button>@endif</form>
@elseif($challenge->is_ranked)
@if($rankedMatch)<p>Esta série vale ELO/PDL. O placar é enviado e confirmado na página da partida ranqueada.</p><a class="btn btn-primary" href="{{ route('ranked.matches.show', $rankedMatch) }}">Abrir partida ranqueada</a>@else<p>Este convite foi encerrado.</p>@endif
@elseif($match)
@if($match->challenger_games !== null)<h2>{{ $match->challenger_games }} × {{ $match->opponent_games }}</h2><p>Placar na ordem: {{ $challenge->challenger->nickname }} / {{ $challenge->opponent->nickname }}</p>@if($match->game_order)<p>Vencedores dos games: {{ implode(', ', $match->game_order) }}</p>@endif @endif
@if($match->status === 'confirmed')<p>✓ Resultado confirmado pelos dois jogadores em {{ $match->confirmed_at->format('d/m/Y H:i') }}.</p><a href="{{ route('challenges.rivalries') }}">Ver rivalidades</a>
@elseif($match->status === 'reported')
<h2>Aguardando confirmação</h2>
@if($match->reported_by !== auth()->id())<form method="POST" action="{{ route('challenges.review', $challenge) }}">@csrf<input type="hidden" name="revision" value="{{ $match->revision }}"><p>Confira o placar e a ordem antes de confirmar. A confirmação é definitiva.</p><button class="btn btn-primary" name="action" value="confirm">Confirmar placar</button><label>Motivo da contestação<textarea name="reason" maxlength="1000">{{ old('reason') }}</textarea></label><button class="btn btn-secondary" name="action" value="dispute">Contestar placar</button></form>@else<p>O adversário precisa confirmar ou contestar este placar.</p>@endif
@else
@if($match->status === 'disputed')<div class="alert alert-danger"><strong>Placar contestado</strong><p>{{ $match->dispute_reason }}</p><p>Conversem e enviem um placar corrigido. Uma nova confirmação será necessária.</p></div>@endif
<h2>Informar resultado</h2><p class="life-cta"><a class="btn btn-primary" href="{{ route('life.challenge', $challenge) }}">❤ Abrir contador de vida</a> <small>Placar e ordem dos games preenchidos sozinhos.</small></p><form method="POST" action="{{ route('challenges.report', $challenge) }}">@csrf
<label>Games de {{ $challenge->challenger->nickname }} (1)<input type="number" name="challenger_games" min="0" max="{{ intdiv($challenge->best_of,2)+1 }}" required value="{{ old('challenger_games') }}"></label>
<label>Games de {{ $challenge->opponent->nickname }} (2)<input type="number" name="opponent_games" min="0" max="{{ intdiv($challenge->best_of,2)+1 }}" required value="{{ old('opponent_games') }}"></label>
<label>Ordem dos vencedores (opcional)<input name="game_order" maxlength="20" placeholder="2,1,1" value="{{ old('game_order') }}" pattern="[12](,[12])*" aria-describedby="order-help"></label><p id="order-help">Use 1 para {{ $challenge->challenger->nickname }} e 2 para {{ $challenge->opponent->nickname }}, separados por vírgula. Registre todos os games na ordem real.</p><button class="btn btn-primary">Enviar para confirmação</button></form>
@endif
@else<p>Este convite foi encerrado. Você pode enviar outro na página de desafios.</p>@endif
</section>
@endsection
