@extends('layouts.app')
@section('content')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/hub.css') }}">@endpush
<div class="invite-page">
    <x-ui.card>
        <div class="invite-hero">
            <span class="club-mark is-lg" aria-hidden="true">{{ mb_strtoupper(mb_substr($club->name, 0, 2)) }}</span>
            <p class="muted small">Você foi convidado para o clube</p>
            <h1>{{ $club->name }}</h1>
            <p class="muted">{{ $club->members_count }} {{ $club->members_count === 1 ? 'membro' : 'membros' }}@if($club->location) · {{ $club->location }}@endif</p>
            @if($club->description)<p>{{ $club->description }}</p>@endif
            @if($isMember)
                <a class="ui-btn ui-btn-primary" href="{{ route('clubs.show', $club) }}">Você já é membro, abrir clube</a>
            @else
                <form method="POST" action="{{ route('clubs.invite.accept', $token) }}">@csrf<button class="ui-btn ui-btn-primary"><x-icon name="user-plus" />Entrar no clube</button></form>
            @endif
        </div>
    </x-ui.card>
</div>
@endsection
