@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="card auth-card invite-card"><span class="eyebrow">Convite para torneio</span><h1>{{ $tournament->name }}</h1><p class="muted">{{ $tournament->format }} @if($tournament->tournament_date) · {{ $tournament->tournament_date->format('d/m/Y') }} @endif</p>
@if($tournament->status===\App\Models\Tournament::STATUS_SETUP)
@if($entry)
<div class="invite-note">Você já está inscrito como <strong>{{ $entry->name }}</strong> com <strong>{{ $entry->deck_name ?: 'Deck surpresa' }}</strong>. Pode trocar o deck até o torneio começar.</div>
<form method="POST" action="{{ route('tournaments.entry.update',$tournament) }}">@csrf @method('PATCH')
@include('tournaments.partials.deck-picker', ['profile' => $profile, 'current' => $entry, 'pickerId' => 'invite'])
<button class="btn btn-primary btn-block">Trocar deck</button></form>
<p class="auth-link"><a href="{{ route('arena.public',$tournament->public_slug) }}">Ver placar do torneio</a></p>
@else
<div class="invite-note">Você entrará como <strong>{{ $profile->nickname }}</strong>. Resultados e conquistas ficarão no seu perfil.</div>
<form method="POST" action="{{ route('tournaments.invite.store',$tournament->invite_token) }}">@csrf
@include('tournaments.partials.deck-picker', ['profile' => $profile, 'current' => null, 'pickerId' => 'invite'])
<button class="btn btn-primary btn-block">Entrar no torneio</button></form>
<p class="auth-link"><a href="{{ route('profiles.edit') }}">Editar perfil e decks</a></p>
@endif
@else<div class="closed-state"><strong>Inscrições encerradas</strong><p>Este torneio já começou.</p></div>@endif
</div></div>
@endsection
