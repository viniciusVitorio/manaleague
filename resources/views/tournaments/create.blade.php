@extends('layouts.app')
@section('content')
<div class="tournament-form-shell">
    <a class="back-link" href="{{ route('tournaments.index') }}">← Torneios</a>
    <div class="page-head"><div><span class="eyebrow">Torneios</span><h1>Criar torneio</h1><p class="muted">Configure a disputa e compartilhe o convite.</p></div></div>
    <form method="POST" action="{{ route('tournaments.store') }}" data-tournament-form>
        @csrf
        @include('tournaments.partials.form-fields')
        <div class="tournament-form-actions"><a class="ui-btn ui-btn-ghost" href="{{ route('tournaments.index') }}">Cancelar</a><button class="ui-btn ui-btn-primary">Criar torneio</button></div>
    </form>
</div>
@endsection
