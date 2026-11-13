@extends('layouts.app')
{{-- Tela ao vivo: atualiza sozinha quando algo muda (live-refresh.js). --}}
@section('live-scope', 'challenge:'.$challenge->id)
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/life-chart.css') }}"><script defer src="{{ \App\Support\VersionedAsset::url('js/life-chart.js') }}"></script>@endpush
@section('content')
@php($match = $challenge->casualMatch)
<a class="back-link" href="{{ route('challenges.index') }}">← Desafios</a>
<header><span class="eyebrow">{{ $challenge->is_ranked ? 'Ranqueada' : 'Casual' }} · {{ $challenge->format }} · MD{{ $challenge->best_of }}</span><h1><x-profile-link :of="$challenge->challenger">{{ $challenge->challenger->displayName() }}</x-profile-link> × <x-profile-link :of="$challenge->opponent">{{ $challenge->opponent->displayName() }}</x-profile-link></h1><p>{{ $challenge->statusLabel() }}</p></header>
<section class="card section-card">
@if($challenge->status === 'pending')
<p>Convite válido até {{ $challenge->expires_at->format('d/m/Y H:i') }}.</p>
<form method="POST" action="{{ route('challenges.transition', $challenge) }}">@csrf
@if($challenge->opponent->account_user_id === auth()->id())<button class="btn btn-primary" name="action" value="accept">Aceitar</button> <button class="btn btn-secondary" name="action" value="decline">Recusar</button>
@else<button class="btn btn-secondary" name="action" value="cancel">Cancelar convite</button>@endif</form>
@elseif($challenge->is_ranked)
@if($rankedMatch)<p>Esta série vale ELO/PDL. O placar é enviado e confirmado na página da partida ranqueada.</p><a class="btn btn-primary" href="{{ route('ranked.matches.show', $rankedMatch) }}">Abrir partida ranqueada</a>@else<p>Este convite foi encerrado.</p>@endif
@elseif($match)
@if($match->challenger_games !== null)<h2>{{ $match->challenger_games }} × {{ $match->opponent_games }}</h2><p>Placar na ordem: <x-profile-link :of="$challenge->challenger">{{ $challenge->challenger->displayName() }}</x-profile-link> / <x-profile-link :of="$challenge->opponent">{{ $challenge->opponent->displayName() }}</x-profile-link></p>@if($match->game_order)<p>Vencedores dos games: {{ implode(', ', $match->game_order) }}</p>@endif @endif
@if($match->status === 'confirmed')<p>@if($match->auto_confirmed)✓ Resultado confirmado automaticamente em {{ $match->confirmed_at->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i') }}: o adversário não confirmou nem contestou em {{ \App\Models\Challenge::CONFIRM_DAYS }} dias.@else✓ Resultado confirmado pelos dois jogadores em {{ $match->confirmed_at->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i') }}.@endif</p><a href="{{ route('challenges.rivalries') }}">Ver rivalidades</a>
@elseif($match->status === 'reported')
<h2>Aguardando confirmação</h2>
@php($autoAt = $match->autoConfirmAt()?->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i'))
@if($match->reported_by !== auth()->id())<form method="POST" action="{{ route('challenges.review', $challenge) }}">@csrf<input type="hidden" name="revision" value="{{ $match->revision }}"><p>Confira o placar e a ordem antes de confirmar. A confirmação é definitiva.</p>@if($autoAt)<p class="muted">Você tem até {{ $autoAt }} para confirmar ou contestar. Depois disso, o placar é confirmado automaticamente.</p>@endif<button class="btn btn-primary" name="action" value="confirm">Confirmar placar</button><label>Motivo da contestação<textarea name="reason" maxlength="1000">{{ old('reason') }}</textarea></label><button class="btn btn-secondary" name="action" value="dispute">Contestar placar</button></form>@else<p>O adversário precisa confirmar ou contestar este placar.</p>@if($autoAt)<p class="muted">Se ele não responder até {{ $autoAt }}, o placar é confirmado automaticamente.</p>@endif @endif
@else
@if($match->status === 'disputed')<div class="alert alert-danger"><strong>Placar contestado</strong><p>{{ $match->dispute_reason }}</p><p>Conversem e enviem um placar corrigido. Uma nova confirmação será necessária.</p></div>@endif
<h2>Informar resultado</h2><p class="life-cta"><a class="btn btn-primary" href="{{ route('life.challenge', $challenge) }}">❤ Abrir contador de vida</a> <small>Placar e ordem dos games preenchidos sozinhos.</small></p><form method="POST" action="{{ route('challenges.report', $challenge) }}">@csrf
<label>Games de {{ $challenge->challenger->displayName() }} (1)<input type="number" name="challenger_games" min="0" max="{{ intdiv($challenge->best_of,2)+1 }}" required value="{{ old('challenger_games') }}"></label>
<label>Games de {{ $challenge->opponent->displayName() }} (2)<input type="number" name="opponent_games" min="0" max="{{ intdiv($challenge->best_of,2)+1 }}" required value="{{ old('opponent_games') }}"></label>
<label>Ordem dos vencedores (opcional)<input name="game_order" maxlength="20" placeholder="2,1,1" value="{{ old('game_order') }}" pattern="[12](,[12])*" aria-describedby="order-help"></label><p id="order-help">Use 1 para {{ $challenge->challenger->displayName() }} e 2 para {{ $challenge->opponent->displayName() }}, separados por vírgula. Registre todos os games na ordem real.</p><button class="btn btn-primary">Enviar para confirmação</button></form>
@endif
@else<p>Este convite foi encerrado. Você pode enviar outro na página de desafios.</p>@endif
</section>
{{-- Gráfico da partida: só quando ela foi jogada com o contador de vida. --}}
@if($lifeChart)
<section class="card section-card" aria-labelledby="life-chart-heading"><h2 id="life-chart-heading">Gráfico da partida</h2>@include('life.partials.chart', ['chart' => $lifeChart])</section>
@endif
@endsection
