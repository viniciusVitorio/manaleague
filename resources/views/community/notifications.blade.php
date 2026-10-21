@extends('layouts.app')
@section('content')
@include('community.nav')
@php
    $unreadCount = $notifications->getCollection()->whereNull('read_at')->count();
    $categories = ['tournament' => ['Torneios', 'Rodadas prontas e resultados finais', 'trophy'], 'match' => ['Partidas', 'Placares enviados, confirmados e contestados', 'target'],
        'challenge' => ['Desafios', 'Convites, aceites e placares de desafios', 'swords'], 'ranked' => ['Ranked', 'Partidas ranqueadas e variação de pontos', 'chart'],
        'club' => ['Clubes', 'Convites e mudanças nos seus clubes', 'shield'], 'achievement' => ['Conquistas', 'Conquistas desbloqueadas', 'crown']];
@endphp
<header class="page-head">
    <div><h1>Notificações</h1><p>Avisos do ManaLeague sobre suas partidas, torneios e desafios.</p></div>
    @if($unreadCount)<form method="POST" action="{{ route('notifications.read-all') }}">@csrf @method('PATCH')<button class="ui-btn ui-btn-outline"><x-icon name="check-check" />Marcar todas como lidas</button></form>@endif
</header>

<div class="notif-layout">
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

    <x-ui.card title="Preferências" icon="settings">
        <form method="POST" action="{{ route('notifications.preferences') }}">@csrf @method('PATCH')
            <input type="hidden" name="enabled" value="0">
            <label class="ui-option ui-option-box">
                <span class="ui-option-text"><span class="ui-option-title">Receber notificações</span><span class="ui-option-desc">Somente dentro do app. E-mail não está ativado.</span></span>
                <input type="checkbox" class="ui-switch" role="switch" name="enabled" value="1" @checked($preference->enabled)>
            </label>
            <p class="ui-card-meta" style="margin:16px 0 4px">Categorias</p>
            @foreach($categories as $type => [$label, $desc, $icon])
                <label class="ui-option">
                    <span class="ui-option-text"><span class="ui-option-title">{{ $label }}</span><span class="ui-option-desc">{{ $desc }}</span></span>
                    {{-- Ligado = recebe. O formulario envia as categorias DESLIGADAS como silenciadas. --}}
                    <input type="checkbox" class="ui-switch" role="switch" data-mute="{{ $type }}" @checked(! in_array($type, $preference->muted_types ?? []))>
                    <input type="hidden" name="muted_types[]" value="{{ $type }}" @disabled(! in_array($type, $preference->muted_types ?? [])) data-mute-field="{{ $type }}">
                </label>
            @endforeach
            <button class="ui-btn ui-btn-primary" style="margin-top:16px;width:100%">Salvar preferências</button>
        </form>
    </x-ui.card>
</div>
@endsection
