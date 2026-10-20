@extends('layouts.app')
@push('head')
<script src="{{ \App\Support\VersionedAsset::url('js/deck-simulator.js') }}" defer></script>
@endpush
@section('content')
@include('decks.tools-nav')
<header class="page-heading"><span class="eyebrow">Treino sem alterar seu deck</span><h1>Simulador · {{ $deck->name }}</h1><a href="{{ route('decks.show', $deck) }}">Voltar ao deck</a></header>
<section class="card section-card" data-simulator data-cards="{{ $cards->toJson() }}">
<h2>Mulligan de Londres</h2>
<p>Cada mulligan embaralha novamente e compra sete. Ao manter, selecione uma carta por mulligan para o fundo, na ordem dos cliques. Sideboard e comandantes ficam fora do grimório.</p>
<label class="check-label"><input type="checkbox" data-free-mulligan> Primeiro mulligan grátis (multiplayer; reinicia a mão)</label>
@if($cards->sum('quantity') < 7)
<p class="tools-note">Grimório incompleto: há menos de sete cartas. A simulação usará somente as cartas disponíveis.</p>
@endif
<p data-status role="status" aria-live="polite"></p>
<div class="sim-actions">
<button type="button" class="btn btn-secondary" data-action="mulligan">Mulligan</button>
<button type="button" class="btn btn-primary" data-action="keep">Manter mão</button>
<button type="button" class="btn btn-secondary" data-action="draw">Comprar carta</button>
<button type="button" class="btn btn-ghost" data-action="reset">Nova mão</button>
</div>
<div class="sim-hand" data-hand aria-label="Cartas na mão"></div>
<h2>Estatísticas de mãos iniciais</h2><p>Amostragem aleatória de terrenos; não prevê vitórias nem aplica mulligans automáticos. Cartas de duas faces usam o tipo cadastrado.</p>
<button type="button" class="btn btn-secondary" data-action="stats">Simular 1.000 mãos</button>
<div data-statistics aria-live="polite"></div>
<noscript>Ative JavaScript para usar o simulador.</noscript>
</section>
@endsection
