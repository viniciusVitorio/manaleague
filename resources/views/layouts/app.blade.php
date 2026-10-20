<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'ManaLeague' }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <meta name="theme-color" content="#09090b">
    <script src="{{ asset('js/nav.js') }}" defer></script>
    @stack('head')
</head>
<body>
<nav class="topbar">
    <div class="container nav-inner">
        <a class="brand" href="{{ auth()->check() ? route('tournaments.index') : route('home') }}"><span class="brand-mark">M</span><span>ManaLeague</span></a>
        @auth
            @php($pendingFriends = auth()->user()->playerProfile?->friendedBy()->wherePivot('status', 'pending')->count() ?? 0)
            <button type="button" class="nav-toggle" aria-controls="main-menu" aria-expanded="false" aria-label="Abrir menu">
                <span class="nav-toggle-bar"></span><span class="nav-toggle-bar"></span><span class="nav-toggle-bar"></span>
                @if($pendingFriends)<span class="nav-toggle-dot" aria-hidden="true"></span>@endif
            </button>
            <div class="nav-actions nav-menu" id="main-menu"><a class="nav-link" href="{{ route('tournaments.index') }}">Torneios</a><a class="nav-link" href="{{ route('friends.index') }}">Amigos @if($pendingFriends)<span class="nav-badge" aria-label="{{ $pendingFriends }} pedidos pendentes">{{ $pendingFriends }}</span>@endif</a><a class="nav-link" href="{{ route('profiles.index') }}">Meu perfil</a><form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-ghost btn-small">Sair</button></form></div>
        @else
            <div class="nav-actions"><a class="btn btn-ghost btn-small" href="{{ route('login') }}">Entrar</a><a class="btn btn-primary btn-small nav-create" href="{{ route('register') }}">Criar conta</a></div>
        @endauth
    </div>
</nav>
<main class="container page">
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @yield('content')
</main>
<footer class="footer">ManaLeague &middot; Gestao simples e segura de torneios.</footer>
</body>
</html>
