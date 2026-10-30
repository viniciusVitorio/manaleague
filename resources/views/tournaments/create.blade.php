@extends('layouts.app')
@section('content')
<div class="form-shell card"><span class="eyebrow">Torneios</span><h1>Criar torneio</h1><p class="muted">Configure a arena e depois compartilhe o convite.</p><form method="POST" action="{{ route('tournaments.store') }}">@csrf
<label>Nome do torneio<input name="name" value="{{ old('name',$tournament->name??'') }}" maxlength="120" required autofocus></label>
<label>Data<input type="date" name="tournament_date" value="{{ old('tournament_date',isset($tournament)?$tournament->tournament_date?->format('Y-m-d'):now()->format('Y-m-d')) }}" required></label>
<label>Formato<select name="format" required>@foreach(['Pauper','Commander','Standard','Modern','Draft','Outro'] as $format)<option value="{{ $format }}" @selected(old('format',$tournament->format??'Pauper')===$format)>{{ $format }}</option>@endforeach</select></label>
@include('tournaments.partials.rules-fields')
@include('tournaments.partials.competition-fields')
<label>Limite de participantes <small>Opcional, entre 4 e 128</small><input type="number" name="max_players" min="4" max="128" value="{{ old('max_players',$tournament->max_players??'') }}" placeholder="Sem limite"></label>
<div class="button-row"><button class="btn btn-primary">Criar torneio</button><a class="btn btn-ghost" href="{{ route('tournaments.index') }}">Cancelar</a></div></form></div>
@endsection
