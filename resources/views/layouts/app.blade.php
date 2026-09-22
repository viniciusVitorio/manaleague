<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'ManaLeague' }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<nav class="topbar">
    <div class="container nav-inner">
        <a class="brand" href="{{ auth()->check() ? route('tournaments.index') : route('home') }}"><span class="brand-mark">M</span><span>ManaLeague</span></a>
        @auth
            <div class="nav-actions"><a class="nav-link" href="{{ route('profiles.index') }}">Meu perfil</a><span class="nav-user">{{ auth()->user()->name }}</span><form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-ghost btn-small">Sair</button></form></div>
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
