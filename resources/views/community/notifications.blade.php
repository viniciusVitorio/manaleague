@extends('layouts.app')
{{-- Tela ao vivo: atualiza sozinha quando algo muda (live-refresh.js). --}}
@section('live-scope', 'me')
@section('content')
@include('community.nav')
@php
    $unreadCount = $notifications->getCollection()->whereNull('read_at')->count();
@endphp
<header class="page-head">
    <div><h1>Notificações</h1><p>Avisos do ManaLeague sobre suas partidas, eventos e desafios.</p></div>
    <div class="page-head-actions">
        <a class="ui-btn ui-btn-ghost" href="{{ route('profiles.edit') }}#notificacoes"><x-icon name="settings" />Configurar notificações</a>
        @if($unreadCount)<form method="POST" action="{{ route('notifications.read-all') }}">@csrf @method('PATCH')<button class="ui-btn ui-btn-outline"><x-icon name="check-check" />Marcar todas como lidas</button></form>@endif
    </div>
</header>

    <x-ui.card title="Caixa de entrada" icon="inbox" :meta="$notifications->total() ? $notifications->total().' no total' : null">
        @if($notifications->isEmpty())
            <x-ui.empty icon="bell" title="Tudo em dia por aqui." text="Quando houver rodada pronta, placar para confirmar ou desafio, aparece aqui." />
        @else
            <div class="ui-list">
            @foreach($notifications as $notification)
                <div @class(['notif-item', 'is-unread' => ! $notification->read_at])>
                    <span class="notif-dot" aria-hidden="true"></span>
                    <a class="ui-row-main" href="{{ $notification->url }}">
                        <span class="ui-row-title">{{ $notification->title }}</span>
                        <span class="ui-row-sub"><time datetime="{{ $notification->created_at->toIso8601String() }}" title="{{ $notification->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}">{{ $notification->created_at->diffForHumans() }}</time>@unless($notification->read_at)<span class="sr-only"> · não lida</span>@endunless</span>
                    </a>
                    @unless($notification->read_at)
                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf @method('PATCH')<button class="ui-btn ui-btn-ghost ui-btn-sm" aria-label="Marcar como lida: {{ $notification->title }}"><x-icon name="circle-check" /></button></form>
                    @endunless
                </div>
            @endforeach
            </div>
            {{ $notifications->links() }}
        @endif
    </x-ui.card>
@endsection
