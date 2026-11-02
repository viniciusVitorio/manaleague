@extends('layouts.app')
{{-- Tela ao vivo: atualiza sozinha quando algo muda (live-refresh.js). --}}
@section('live-scope', 'me')
@section('content')
<header class="page-head">
    <div><span class="eyebrow">Mesa casual</span><h1>Desafios</h1><p>Séries 1v1 contra amigos, casuais ou valendo ranque. O placar só conta depois que os dois confirmam.</p></div>
    <div class="page-head-actions">
        <a class="ui-btn ui-btn-outline" href="{{ route('challenges.rivalries') }}"><x-icon name="users" />Rivalidades</a>
        <a class="ui-btn ui-btn-primary" href="{{ route('challenges.create') }}"><x-icon name="swords" />Desafiar amigo</a>
    </div>
</header>

@include('partials.confirm-all')

@if($open->isEmpty() && $history->isEmpty())
    <x-ui.card>
        @if($hasFriends)
            <x-ui.empty icon="swords" title="Nenhum desafio ainda." text="Chame um amigo para a primeira série. Quando os dois confirmarem o placar, ela aparece aqui." :href="route('challenges.create')" action="Desafiar amigo" />
        @else
            <x-ui.empty icon="user-plus" title="Nenhum desafio ainda." text="Desafios são entre amigos com conta. Adicione alguém para mandar o primeiro convite." :href="route('friends.index')" action="Encontrar amigos" />
        @endif
    </x-ui.card>
@else
    @if($open->isNotEmpty())
        <x-ui.card title="Em andamento" icon="clock" :meta="$open->count().' '.($open->count() === 1 ? 'desafio' : 'desafios')" class="challenge-list">
            @foreach($open as $row)
                @include('challenges.partials.row', ['row' => $row])
            @endforeach
        </x-ui.card>
    @endif

    <x-ui.card title="Histórico" icon="calendar" :meta="$history->total() ? $history->total().' '.($history->total() === 1 ? 'desafio' : 'desafios') : null" class="challenge-list">
        @forelse($history as $row)
            @include('challenges.partials.row', ['row' => $row])
        @empty
            <p class="muted small-note">Nenhum desafio encerrado ainda. As séries confirmadas, recusadas e expiradas ficam aqui.</p>
        @endforelse
        {{ $history->links() }}
    </x-ui.card>
@endif
@endsection
