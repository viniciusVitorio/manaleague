<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'ManaLeague' }}</title>
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/app.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/ui.css') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <meta name="theme-color" content="#09090b">
    <script src="{{ \App\Support\VersionedAsset::url('js/nav.js') }}" defer></script>
    <script src="{{ \App\Support\VersionedAsset::url('js/release-notice.js') }}" defer></script>
    @stack('head')
</head>
<body>
<div id="release-notice" class="alert alert-success" role="status" hidden data-release="{{ \App\Support\VersionedAsset::coreRelease() }}" data-check-url="{{ route('home') }}">
    Nova versão do ManaLeague disponível. Salve suas alterações antes de atualizar.
    <button type="button" class="btn btn-small btn-secondary" data-release-reload>Atualizar página</button>
    <button type="button" class="btn btn-small btn-ghost" data-release-dismiss>Depois</button>
</div>
@auth
@include('partials.site-header')
@else
<nav class="topbar">
    <div class="container nav-inner">
        <a class="brand" href="{{ route('home') }}"><span class="brand-mark">M</span><span>ManaLeague</span></a>
        <div class="nav-actions"><a class="btn btn-ghost btn-small" href="{{ route('login') }}">Entrar</a><a class="btn btn-primary btn-small nav-create" href="{{ route('register') }}">Criar conta</a></div>
    </div>
</nav>
@endauth
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
