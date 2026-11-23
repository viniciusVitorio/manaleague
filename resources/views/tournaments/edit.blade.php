@extends('layouts.app')
@section('content')
<a class="back-link" href="{{ route('tournaments.show', $tournament) }}">← Voltar ao {{ $tournament->kindNoun() }}</a>
<header class="page-head">
    <div><h1>Editar {{ $tournament->kindNoun() }}</h1><p>{{ $tournament->name }}. Dá para mudar tudo até o {{ $tournament->kindNoun() }} começar.</p></div>
</header>
<form method="POST" action="{{ route('tournaments.update', $tournament) }}" class="event-form">@csrf @method('PUT')
    @include('tournaments.partials.event-form')
    <div class="event-form-actions">
        <a class="ui-btn ui-btn-ghost" href="{{ route('tournaments.show', $tournament) }}">Cancelar</a>
        <button class="ui-btn ui-btn-primary">Salvar alterações</button>
    </div>
</form>

<x-ui.card title="Link de convite" icon="link" class="event-invite">
    <p class="muted small">Ao gerar outro link, o anterior deixa de funcionar imediatamente.</p>
    <form method="POST" action="{{ route('tournaments.invite.regenerate', $tournament) }}" data-confirm="Invalidar o convite atual?">@csrf<button class="ui-btn ui-btn-outline"><x-icon name="repeat" />Gerar novo link</button></form>
</x-ui.card>
@endsection
