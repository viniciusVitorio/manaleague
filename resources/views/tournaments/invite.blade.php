@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="card auth-card invite-card"><span class="eyebrow">Convite para torneio</span><h1>{{ $tournament->name }}</h1><p class="muted">{{ $tournament->format }} @if($tournament->tournament_date) · {{ $tournament->tournament_date->format('d/m/Y') }} @endif</p>
@if($tournament->status===\App\Models\Tournament::STATUS_SETUP)
<div class="invite-note">Você entrará como <strong>{{ $profile->nickname }}</strong>. Resultados e conquistas ficarão no seu perfil.</div>
<form method="POST" action="{{ route('tournaments.invite.store',$tournament->invite_token) }}">@csrf
@if($profile->decks()->exists())
<label>Escolha um deck<select id="saved-deck" onchange="const o=this.options[this.selectedIndex];document.getElementById('deck-name').value=o.dataset.name||'';document.getElementById('deck-colors').value=o.dataset.colors||''"><option value="">Selecionar...</option>@foreach($profile->decks as $deck)<option data-name="{{ $deck->name }}" data-colors="{{ $deck->colors }}" @selected($deck->is_primary)>{{ $deck->name }} {{ $deck->colors }}</option>@endforeach</select></label>
@endif
<label>Deck usado<input id="deck-name" name="deck_name" value="{{ old('deck_name',$profile->preferred_deck_name) }}" maxlength="100"></label>
<label>Cores<select id="deck-colors" name="deck_colors"><option value="">Não informar</option>@foreach(['W','U','B','R','G','WU','UB','BR','RG','GW','WB','UR','BG','RW','GU','WUB','UBR','BRG','RGW','GWU','WUBRG','C'] as $color)<option value="{{ $color }}" @selected(old('deck_colors',$profile->preferred_deck_colors)===$color)>{{ $color }}</option>@endforeach</select></label>
<button class="btn btn-primary btn-block">Entrar no torneio</button></form>
<p class="auth-link"><a href="{{ route('profiles.edit') }}">Editar perfil e decks</a></p>
@else<div class="closed-state"><strong>Inscrições encerradas</strong><p>Este torneio já começou.</p></div>@endif
</div></div>
@endsection
