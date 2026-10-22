<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#09090b">
    <title>Contador de vida · {{ $config['title'] }} · ManaLeague</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/life.css') }}">
    <script src="{{ \App\Support\VersionedAsset::url('js/life-counter.js') }}" defer></script>
</head>
<body class="life-body">
<main id="life-counter" class="life-counter life-{{ $config['mode'] }}" data-config="{{ json_encode($config, JSON_THROW_ON_ERROR) }}" aria-label="Contador de vida">
    <noscript><div class="life-noscript">O contador de vida precisa de JavaScript. <a href="{{ $config['resultUrl'] }}">Voltar para a partida</a></div></noscript>
</main>
</body>
</html>
