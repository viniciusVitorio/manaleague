@extends('layouts.app')
@section('content')
<header class="section-heading"><div><span class="eyebrow">Mesa casual</span><h1>Desafios 1v1</h1><p>Resultados separados dos torneios. Só contam após confirmação dos dois jogadores.</p></div><a class="btn btn-secondary" href="{{ route('challenges.rivalries') }}">Rivalidades</a></header>
<section class="card section-card"><h2>Desafiar um amigo</h2>
@if($friends->isEmpty())<p>Adicione um amigo com conta para enviar um convite.</p><a href="{{ route('friends.index') }}">Encontrar amigos</a>
@else
<form method="POST" action="{{ route('challenges.store') }}" class="form-grid">@csrf
<label>Adversário<select name="opponent_id" required>@foreach($friends as $friend)<option value="{{ $friend->id }}" @selected(old('opponent_id', request('opponent')) == $friend->id)>{{ $friend->nickname }}</option>@endforeach</select></label>
<label>Formato<input name="format" maxlength="60" value="{{ old('format', 'Pauper') }}" required></label>
<label>Melhor de<select name="best_of">@foreach([1,3,5] as $n)<option value="{{ $n }}" @selected(old('best_of', 3) == $n)>{{ $n }} games</option>@endforeach</select></label>
<label class="ui-option ui-option-box"><span class="ui-option-text"><span class="ui-option-title">Partida ranqueada</span><span class="ui-option-desc">Vale ELO/PDL nos formatos {{ implode(', ', config('ranked.formats')) }}. Máximo de {{ config('ranked.defaults.pair_limit_24h') }} ranqueadas com o mesmo amigo a cada 24h.</span></span><input type="checkbox" class="ui-switch" role="switch" name="ranked" value="1" @checked(old('ranked', request('ranked')))></label>
<label>Validade do convite<select name="expires_in"><option value="24">24 horas</option><option value="48">48 horas</option><option value="168">7 dias</option></select></label><button class="btn btn-primary">Enviar desafio</button></form>
@endif</section>
<section class="card section-card"><h2>Meus desafios</h2>
@forelse($challenges as $challenge)<a class="history-row" href="{{ route('challenges.show', $challenge) }}"><div><strong>{{ $challenge->challenger->displayName() }} × {{ $challenge->opponent->displayName() }}</strong><small>@if($challenge->is_ranked)<b>Ranqueada</b> · @endif{{ $challenge->format }} · MD{{ $challenge->best_of }} · {{ $challenge->statusLabel() }}</small></div><span>Ver →</span></a>@empty<p>Nenhum desafio ainda. Convide um amigo para a primeira série.</p>@endforelse
{{ $challenges->links() }}</section>
@endsection
