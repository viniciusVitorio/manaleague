@extends('layouts.app')
@section('content')
@include('decks.tools-nav')
<header class="page-heading"><span class="eyebrow">Memória de jogo</span><h1>Histórico de decks</h1><p>Versões imutáveis, incluindo decks excluídos. Apenas você pode consultar este arquivo.</p></header>
<div class="tools-grid">@forelse($versions as $version)<article class="card section-card"><span class="eyebrow">Versão {{ $version->number }} · {{ $version->created_at->format('d/m/Y H:i') }}</span><h2><a href="{{ route('decks.versions.show', $version) }}">{{ $version->payload['name'] }}</a></h2><p>{{ $version->label }}</p><p>{{ collect($version->payload['cards'])->sum('quantity') }} cartas · {{ $version->payload['format'] ?: 'Livre' }}</p></article>@empty<div class="card section-card"><h2>Seu histórico começa aqui</h2><p>Salve uma versão na página de um deck. Participações em torneios preservam automaticamente o estado usado na largada.</p></div>@endforelse</div>
{{ $versions->links() }}
@endsection
