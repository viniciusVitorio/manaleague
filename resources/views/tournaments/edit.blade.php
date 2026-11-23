@extends('layouts.app')
@section('content')
<div class="tournament-form-shell">
    <a class="back-link" href="{{ route('tournaments.show', $tournament) }}">← Voltar ao torneio</a>
    <div class="page-head"><div><span class="eyebrow">Configurações</span><h1>Editar torneio</h1><p class="muted">Ajuste a disputa antes do início.</p></div></div>
    <form method="POST" action="{{ route('tournaments.update', $tournament) }}" data-tournament-form>
        @csrf
        @method('PUT')
        @include('tournaments.partials.form-fields')
        <div class="tournament-form-actions"><a class="ui-btn ui-btn-ghost" href="{{ route('tournaments.show', $tournament) }}">Cancelar</a><button class="ui-btn ui-btn-primary">Salvar alterações</button></div>
    </form>
    <section class="card settings-card tournament-form-invite"><div class="settings-card-body"><h2>Link de convite</h2><p class="settings-card-desc">Um novo link invalida o convite anterior.</p>
        <form method="POST" action="{{ route('tournaments.invite.regenerate', $tournament) }}" data-confirm="Invalidar o convite atual?">@csrf<button class="ui-btn ui-btn-outline">Gerar novo link</button></form>
    </div></section>
</div>
@endsection
