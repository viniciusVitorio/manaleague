@extends('layouts.app')
@section('content')
<div class="form-shell card"><a class="back-link" href="{{ route('profiles.edit') }}">← Meus decks</a><span class="eyebrow">Arsenal</span><h1>Editar deck</h1>
<form method="POST" action="{{ route('decks.update',$deck) }}">@csrf @method('PATCH')
<label>Nome<input name="name" value="{{ old('name',$deck->name) }}" maxlength="100" required></label>
<label>Cores<select name="colors"><option value="">Não informar</option>@foreach(['W','U','B','R','G','WU','UB','BR','RG','GW','WB','UR','BG','RW','GU','WUB','UBR','BRG','RGW','GWU','WUBRG','C'] as $color)<option value="{{ $color }}" @selected(old('colors',$deck->colors)===$color)>{{ $color }}</option>@endforeach</select></label>
<label class="check-label"><input type="checkbox" name="is_primary" value="1" @checked($deck->is_primary)> Deck principal</label>
<div class="button-row"><button class="btn btn-primary">Salvar alterações</button><a class="btn btn-ghost" href="{{ route('profiles.edit') }}">Cancelar</a></div></form></div>
@endsection
