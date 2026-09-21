@extends('layouts.app')
@section('content')
<header class="hero compact">
    <div><span class="eyebrow">Painel do organizador</span><h1>Seus torneios</h1><p>Organize participantes, confrontos e resultados em um só lugar.</p></div>
    <a class="btn btn-primary" href="{{ route('tournaments.create') }}">Novo torneio</a>
</header>

<div class="card-grid">
@forelse($tournaments as $tournament)
    @php($statusLabel = match($tournament->status) {'setup' => 'Preparação', 'league' => 'Liga em andamento', 'finals' => 'Finais', 'finished' => 'Encerrado', default => $tournament->status})
    <a class="tournament-card" href="{{ route('tournaments.show', $tournament) }}">
        <div class="card-top"><span class="format-badge">{{ $tournament->format }}</span><span class="status status-{{ $tournament->status }}">{{ $statusLabel }}</span></div>
        <h2>{{ $tournament->name }}</h2>
        <div class="card-meta"><span>{{ $tournament->tournament_date?->format('d/m/Y') ?? 'Data a definir' }} · {{ $tournament->players_count }} jogadores</span><span>Abrir →</span></div>
    </a>
@empty
    <div class="empty-state"><h2>Nenhum torneio criado</h2><p>Comece com quatro amigos e deixe o ManaLeague montar as rodadas.</p><a class="btn btn-primary" href="{{ route('tournaments.create') }}">Criar torneio</a></div>
@endforelse
</div>
@endsection
