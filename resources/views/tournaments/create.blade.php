@extends('layouts.app')
@section('content')
<a class="back-link" href="{{ route('tournaments.index') }}">← Eventos</a>
<header class="page-head">
    <div><h1>Novo evento</h1><p>Diga quando e qual formato, escolha se é campeonato ou torneio e depois mande o convite para a galera.</p></div>
</header>
<form method="POST" action="{{ route('tournaments.store') }}" class="event-form">@csrf
    @include('tournaments.partials.event-form')
    <div class="event-form-actions">
        <a class="ui-btn ui-btn-ghost" href="{{ route('tournaments.index') }}">Cancelar</a>
        <button class="ui-btn ui-btn-primary"><x-icon name="plus" />Criar evento</button>
    </div>
</form>
@endsection
