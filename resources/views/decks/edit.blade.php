@extends('layouts.app')
@section('content')
<div class="form-shell card"><a class="back-link" href="{{ route('decks.show', $deck) }}">← Voltar ao deck</a><span class="eyebrow">Arsenal</span><h1>Editar deck</h1>
<form method="POST" action="{{ route('decks.update', $deck) }}">@csrf @method('PATCH')
<label>Nome<input name="name" value="{{ old('name', $deck->name) }}" maxlength="100" required></label>
<label>Formato<select name="format"><option value="">Livre</option>@foreach(\App\Models\Deck::FORMATS as $format)<option value="{{ $format }}" @selected(old('format', $deck->format) === $format)>{{ $format }}</option>@endforeach</select></label>
<label>Cores <small>ignorado se o deck tiver cartas: as cores vêm da identidade delas</small><select name="colors"><option value="">Não informar</option>@foreach(['W','U','B','R','G','WU','UB','BR','RG','GW','WB','UR','BG','RW','GU','WUB','UBR','BRG','RGW','GWU','WUBRG','C'] as $color)<option value="{{ $color }}" @selected(old('colors', $deck->colors) === $color)>{{ $color }}</option>@endforeach</select></label>
<label class="check-label"><input type="checkbox" name="is_primary" value="1" @checked($deck->is_primary)> Deck principal</label>

<input type="hidden" name="is_visible_to_friends" value="0">
<label class="check-label"><input type="checkbox" name="is_visible_to_friends" value="1" @checked(old('is_visible_to_friends', $deck->is_visible_to_friends))> Mostrar este deck para meus amigos</label>
<p class="muted small-note">Se desmarcar, o deck e sua lista de cartas ficam ocultos no perfil e pelo link direto.</p>
<div class="button-row"><button class="btn btn-primary">Salvar alterações</button><a class="btn btn-ghost" href="{{ route('decks.show', $deck) }}">Cancelar</a></div></form></div>


@endsection
