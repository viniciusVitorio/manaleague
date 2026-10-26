@extends('layouts.app')
@section('content')
@include('decks.tools-nav')
<header class="page-heading"><h1>Faltantes · {{ $deck->name }}</h1><p>Compara com sua coleção, somando main, sideboard e comandante. Cada cópia é contada uma única vez; nenhum item é reservado ou consumido.</p></header>
<form method="GET" class="card section-card tools-form"><label>Correspondência<select name="exact"><option value="0" @selected(!$exact)>Qualquer impressão do mesmo nome</option><option value="1" @selected($exact)>Impressão exata (Scryfall ID)</option></select></label><button class="btn btn-primary">Comparar</button></form>
<section class="card section-card"><p>Todos os acabamentos e condições contam como disponíveis.</p><div class="tools-table-wrap"><table class="tools-table"><thead><tr><th>Carta</th><th>Necessárias</th><th>Na coleção</th><th>Faltam</th></tr></thead><tbody>
@forelse($rows as $row)
<tr><td>{{ $row['name'] }} @if($exact)<small>{{ $row['printing'] }}</small>@endif</td><td>{{ $row['required'] }}</td><td>{{ $row['owned'] }}</td><td>{{ $row['missing'] }}</td></tr>
@empty
<tr><td colspan="4">O deck está vazio.</td></tr>
@endforelse
</tbody></table></div></section>
@if($shoppingList !== '')
<section class="card section-card missing-actions">
    <div class="missing-action">
        <h2>Lista de compras</h2>
        <p class="muted">Só o que falta, pronta para colar numa loja ou no LigaMagic.</p>
        <textarea id="missing-list" rows="6" readonly aria-label="Cartas que faltam">{{ $shoppingList }}</textarea>
        <button type="button" class="btn btn-secondary" data-copy="missing-list" data-copied="Copiado!">Copiar lista</button>
    </div>
    @if($isOwner)
    <form method="POST" action="{{ route('collection.missing.add', $deck) }}" class="missing-action">@csrf
        <h2>Já comprei</h2>
        <p class="muted">Adiciona à sua coleção todas as cópias que faltam, na edição usada no deck.</p>
        <label>Condição<select name="condition">@foreach(\App\Models\CollectionItem::CONDITIONS as $c)<option>{{ $c }}</option>@endforeach</select></label>
        <label>Acabamento<select name="foil"><option value="0">Normal</option><option value="1">Foil</option></select></label>
        <button class="btn btn-primary">Adicionar faltantes à coleção</button>
    </form>
    @endif
</section>
<script src="{{ \App\Support\VersionedAsset::url('js/copy.js') }}" defer></script>
@endif
@endsection
