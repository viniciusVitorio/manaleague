@php
    $user = auth()->user();
    $pendingFriends = $user->playerProfile?->friendedBy()->wherePivot('status', 'pending')->count() ?? 0;
    $unreadNotifications = \App\Models\CommunityNotification::where('user_id', $user->id)->whereNull('read_at')->count();
    $primary = [
        ['dashboard', 'Painel', 'layout-dashboard', 'dashboard'],
        ['tournaments.index', 'Torneios', 'trophy', 'tournaments.*|clubs.tournaments.*|matches.submissions.queue|tournaments.qr.*'],
        ['matches.mine', 'Partidas', 'target', 'matches.mine'],
    ];
    $primary[] = ['challenges.index', 'Desafios', 'swords', 'challenges.*|achievements.*|ranked.matches.*'];
    $primary[] = ['decks.index', 'Decks', 'layers', 'decks.*|collection.missing'];
    $more = [
        ['collection.index', 'Coleção', 'library', 'collection.index'],
        ['clubs.index', 'Clubes', 'shield', 'clubs.*'],
        ['feed.index', 'Comunidade', 'activity', 'feed.index'],
    ];
    $is = fn (string $patterns) => request()->routeIs(...explode('|', $patterns));
    $moreActive = collect($more)->contains(fn ($l) => $is($l[3]));
@endphp
<header class="site-header">
    <div class="container site-header-inner">
        <a class="site-brand" href="{{ route('dashboard') }}" aria-label="ManaLeague, ir para o painel"><span class="brand-mark">M</span><span>ManaLeague</span></a>

        <nav class="site-nav" aria-label="Principal">
            @foreach($primary as [$route, $label, $icon, $pattern])
                <a class="site-nav-link" href="{{ route($route) }}" @if($is($pattern)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            <div class="ui-dropdown">
                <button type="button" class="site-nav-link" data-menu-button aria-expanded="false" aria-controls="menu-more" @if($moreActive) aria-current="page" @endif>Mais<x-icon name="chevron-down" :size="14" /></button>
                <div class="ui-menu" id="menu-more" hidden>
                    @foreach($more as [$route, $label, $icon, $pattern])
                        <a class="ui-menu-item" href="{{ route($route) }}" @if($is($pattern)) aria-current="page" @endif><x-icon :name="$icon" />{{ $label }}</a>
                    @endforeach
                </div>
            </div>
        </nav>

        <div class="site-actions">
            <a class="ui-icon-btn" href="{{ route('notifications.index') }}" @if(request()->routeIs('notifications.*')) aria-current="page" @endif aria-label="Notificações{{ $unreadNotifications ? ', '.$unreadNotifications.' não lidas' : '' }}">
                <x-icon name="bell" />@if($unreadNotifications)<span class="ui-count" aria-hidden="true">{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</span>@endif
            </a>
            @php($isLight = \App\Support\Theme::current() === 'light')
            <form method="POST" action="{{ route('preferences.theme') }}" class="theme-toggle">@csrf @method('PATCH')
                <button class="ui-icon-btn" aria-label="{{ $isLight ? 'Mudar para o tema escuro' : 'Mudar para o tema claro' }}" title="{{ $isLight ? 'Tema escuro' : 'Tema claro' }}"><x-icon :name="$isLight ? 'moon' : 'sun'" /></button>
            </form>
            <div class="ui-dropdown site-account">
                <button type="button" class="ui-avatar-btn" data-menu-button aria-expanded="false" aria-controls="menu-account" aria-label="Conta de {{ $user->name }}">
                    <x-avatar :of="$user" class="ui-avatar" />@if($pendingFriends)<span class="ui-dot-alert" aria-hidden="true"></span>@endif
                </button>
                <div class="ui-menu ui-menu-end" id="menu-account" hidden>
                    <div class="ui-menu-label"><strong>{{ $user->playerProfile?->nickname ?: $user->name }}</strong><span>{{ $user->email }}</span></div>
                    <a class="ui-menu-item" href="{{ route('profiles.index') }}"><x-icon name="user" />Meu perfil</a>
                    <a class="ui-menu-item" href="{{ route('friends.index') }}"><x-icon name="users" />Amigos @if($pendingFriends)<span class="ui-count ui-count-inline" aria-label="{{ $pendingFriends }} {{ $pendingFriends === 1 ? 'pedido de amizade pendente' : 'pedidos de amizade pendentes' }}">{{ $pendingFriends }}</span>@endif</a>
                    @if(config('ranked.enabled') && $user->isAdmin())<a class="ui-menu-item" href="{{ route('ranked.admin') }}"><x-icon name="flag" />Moderação</a>@endif
                    <a class="ui-menu-item" href="{{ route('profiles.edit') }}#preferencias"><x-icon name="settings" />Preferências</a>
                    <div class="ui-menu-sep" role="separator"></div>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="ui-menu-item"><x-icon name="log-out" />Sair</button></form>
                </div>
            </div>
            <button type="button" class="ui-icon-btn site-menu-toggle" data-menu-button aria-expanded="false" aria-controls="menu-mobile" aria-label="Abrir menu">
                <x-icon name="menu" class="when-closed" /><x-icon name="x" class="when-open" />@if($pendingFriends)<span class="ui-dot-alert" aria-hidden="true"></span>@endif
            </button>
        </div>
    </div>

    <nav class="site-mobile" id="menu-mobile" aria-label="Menu" hidden>
        <div class="container">
            @foreach(array_merge($primary, $more) as [$route, $label, $icon, $pattern])
                <a class="site-mobile-link" href="{{ route($route) }}" @if($is($pattern)) aria-current="page" @endif><x-icon :name="$icon" />{{ $label }}</a>
            @endforeach
            <div class="ui-menu-sep" role="separator"></div>
            <a class="site-mobile-link" href="{{ route('profiles.index') }}"><x-icon name="user" />Meu perfil</a>
            <a class="site-mobile-link" href="{{ route('friends.index') }}"><x-icon name="users" />Amigos @if($pendingFriends)<span class="ui-count ui-count-inline" aria-label="{{ $pendingFriends }} {{ $pendingFriends === 1 ? 'pedido de amizade pendente' : 'pedidos de amizade pendentes' }}">{{ $pendingFriends }}</span>@endif</a>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="site-mobile-link"><x-icon name="log-out" />Sair</button></form>
        </div>
    </nav>
</header>
