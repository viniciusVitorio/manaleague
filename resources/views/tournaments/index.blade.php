@extends('layouts.app')
@section('content')
@php
    $statusLabel = fn ($t) => match($t->status) {'setup' => 'Preparação', 'league' => 'Liga em andamento', 'finals' => 'Finais', 'finished' => 'Encerrado', default => $t->status};
@endphp
<header class="hero compact">
    <div><span class="eyebrow">Torneios</span><h1>Seus torneios</h1><p>Os torneios que você organiza e os que você joga, em um só lugar.</p></div>
    <a class="btn btn-primary" href="{{ route('tournaments.create') }}">Novo torneio</a>
</header>

<section class="tournament-section" aria-labelledby="organizing-title">
    <div class="section-heading"><h2 id="organizing-title">Organizados por você @if($tournaments->isNotEmpty())<span class="count">{{ $tournaments->count() }}</span>@endif</h2></div>
    <div class="card-grid">
    @forelse($tournaments as $tournament)
        <a class="tournament-card" href="{{ route('tournaments.show', $tournament) }}">
            <div class="card-top"><span class="format-badge">{{ $tournament->format }}</span><span class="status status-{{ $tournament->status }}">{{ $statusLabel($tournament) }}</span></div>
            <h2>{{ $tournament->name }}</h2>
            <div class="card-meta"><span>{{ $tournament->tournament_date?->format('d/m/Y') ?? 'Data a definir' }} · {{ $tournament->players_count }} jogadores</span><span>Abrir →</span></div>
        </a>
    @empty
        <x-ui.empty class="tournament-empty" icon="trophy" title="Nenhum torneio criado" text="Comece com quatro amigos e deixe o ManaLeague montar as rodadas." />
    @endforelse
    </div>
</section>

<section class="tournament-section" aria-labelledby="playing-title">
    <div class="section-heading"><h2 id="playing-title">Que você participa @if($playing->isNotEmpty())<span class="count">{{ $playing->count() }}</span>@endif</h2></div>
    <div class="card-grid">
    @forelse($playing as $tournament)
        {{-- Quem só joga não gerencia o torneio: abre a página do torneio quando tem acesso (clube) ou a página pública. --}}
        <a class="tournament-card" href="{{ auth()->user()->can('view', $tournament) ? route('tournaments.show', $tournament) : route('arena.public', $tournament->public_slug) }}">
            <div class="card-top"><span class="format-badge">{{ $tournament->format }}</span><span class="status status-{{ $tournament->status }}">{{ $statusLabel($tournament) }}</span></div>
            <h2>{{ $tournament->name }}</h2>
            <div class="card-meta"><span>{{ $tournament->tournament_date?->format('d/m/Y') ?? 'Data a definir' }} · {{ $tournament->players_count }} jogadores @if($tournament->user?->playerProfile)· por {{ $tournament->user->playerProfile->nickname }}@endif</span><span>Ver →</span></div>
        </a>
    @empty
        <x-ui.empty class="tournament-empty" icon="users" title="Você ainda não está em nenhum torneio" text="Quando você entrar em um torneio pelo link de convite, ele aparece aqui." />
    @endforelse
    </div>
</section>
@endsection
