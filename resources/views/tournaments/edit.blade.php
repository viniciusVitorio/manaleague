@extends('layouts.app')
@section('content')
<div class="form-shell card"><a class="back-link" href="{{ route('tournaments.show',$tournament) }}">← Voltar ao torneio</a><span class="eyebrow">Configurações</span><h1>Editar torneio</h1><form method="POST" action="{{ route('tournaments.update',$tournament) }}">@csrf @method('PUT')
<label>Nome do torneio<input name="name" value="{{ old('name',$tournament->name??'') }}" maxlength="120" required autofocus></label>
<label>Data<input type="date" name="tournament_date" value="{{ old('tournament_date',isset($tournament)?$tournament->tournament_date?->format('Y-m-d'):now()->format('Y-m-d')) }}" required></label>
<label>Formato<select name="format" required>@foreach(['Pauper','Commander','Standard','Modern','Draft','Outro'] as $format)<option value="{{ $format }}" @selected(old('format',$tournament->format??'Pauper')===$format)>{{ $format }}</option>@endforeach</select></label>
<label>Limite de participantes <small>Opcional, entre 4 e 128</small><input type="number" name="max_players" min="4" max="128" value="{{ old('max_players',$tournament->max_players??'') }}" placeholder="Sem limite"></label>
<div class="button-row"><button class="btn btn-primary">Salvar alterações</button><a class="btn btn-ghost" href="{{ route('tournaments.show',$tournament) }}">Cancelar</a></div></form>
<hr class="separator"><h2>Link de convite</h2><p class="muted">Ao gerar outro link, o anterior deixa de funcionar imediatamente.</p><form method="POST" action="{{ route('tournaments.invite.regenerate',$tournament) }}" onsubmit="return confirm('Invalidar o convite atual?')">@csrf<button class="btn btn-danger">Gerar novo link</button></form></div>
@endsection
