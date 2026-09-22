@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="card auth-card invite-card"><span class="eyebrow">Convite para torneio</span><h1>{{ $tournament->name }}</h1><p class="muted">{{ $tournament->format }} @if($tournament->tournament_date) · {{ $tournament->tournament_date->format('d/m/Y') }} @endif</p>
@if($tournament->status===\App\Models\Tournament::STATUS_SETUP)
<div class="invite-note">Sua inscrição cria um perfil que guarda decks, resultados e conquistas.</div>
<form method="POST" action="{{ route('tournaments.invite.store',$tournament->invite_token) }}">@csrf
<label>Seu apelido<input name="name" value="{{ old('name') }}" maxlength="80" required autofocus></label>
<label>Avatar <small>Opcional: use um emoji</small><input name="avatar" value="{{ old('avatar') }}" maxlength="20" placeholder="Ex.: 🧙"></label>
<label>Nome do deck <small>Opcional</small><input name="deck_name" value="{{ old('deck_name') }}" maxlength="100" placeholder="Ex.: Rakdos Vampires"></label>
<label>Cores do deck<select name="deck_colors"><option value="">Não informar</option>@foreach(['W','U','B','R','G','WU','UB','BR','RG','GW','WB','UR','BG','RW','GU','WUB','UBR','BRG','RGW','GWU','WUBRG','C'] as $color)<option value="{{ $color }}" @selected(old('deck_colors')===$color)>{{ $color }}</option>@endforeach</select></label>
<button class="btn btn-primary btn-block">Confirmar inscrição</button></form>
@else<div class="closed-state"><strong>Inscrições encerradas</strong><p>Este torneio já começou.</p></div>@endif
</div></div>
@endsection
