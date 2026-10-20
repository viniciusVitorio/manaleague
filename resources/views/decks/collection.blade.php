@extends('layouts.app')
@section('content')
@include('decks.tools-nav')
<header class="page-heading"><span class="eyebrow">Inventário privado</span><h1>Minha coleção</h1><p>Controle por impressão Scryfall, acabamento foil e condição. Sua coleção não é compartilhada com amigos.</p></header>
<section class="card section-card"><h2>Adicionar impressão</h2>
<p>Copie o UUID da impressão no Scryfall (não o Oracle ID). NM: nova; LP: pouco jogada; MP: moderada; HP: muito jogada; DMG: danificada.</p>
<form method="POST" action="{{ route('collection.store') }}" class="tools-form">@csrf
<label>ID da impressão<input name="scryfall_id" required maxlength="36" value="{{ old('scryfall_id') }}" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></label>
<label>Quantidade<input type="number" name="quantity" min="1" max="9999" value="1" required></label>
<label>Condição<select name="condition">@foreach(\App\Models\CollectionItem::CONDITIONS as $condition)<option>{{ $condition }}</option>@endforeach</select></label>
<label>Acabamento<select name="foil"><option value="0">Normal</option><option value="1">Foil</option></select></label>
<button class="btn btn-primary">Adicionar</button></form></section>
<section class="card section-card"><h2>Cartas faltantes para um deck</h2><div class="tools-nav">@forelse($decks as $deck)<a href="{{ route('collection.missing', $deck) }}">{{ $deck->name }}</a>@empty<p>Crie um deck para comparar com sua coleção.</p>@endforelse</div></section>
<section class="card section-card"><h2>Inventário</h2><div class="tools-table-wrap"><table class="tools-table"><thead><tr><th>Carta / impressão</th><th>Acabamento</th><th>Condição</th><th>Quantidade</th><th>Remover</th></tr></thead><tbody>
@forelse($items as $item)
<tr><td>{{ $item->name }}<small>{{ $item->set_code }} · {{ $item->scryfall_id }}</small></td><td>{{ $item->foil ? 'Foil' : 'Normal' }}</td><td>{{ $item->condition }}</td><td><form class="tools-inline-form" method="POST" action="{{ route('collection.update', $item) }}">@csrf @method('PATCH')<input aria-label="Quantidade de {{ $item->name }}" name="quantity" type="number" min="1" max="9999" value="{{ $item->quantity }}" required><button class="btn btn-small">Salvar</button></form></td><td><form method="POST" action="{{ route('collection.destroy', $item) }}">@csrf @method('DELETE')<button class="btn btn-ghost btn-small">Remover</button></form></td></tr>
@empty
<tr><td colspan="5">Nenhuma carta cadastrada.</td></tr>
@endforelse
</tbody></table></div>{{ $items->links() }}</section>
@endsection
