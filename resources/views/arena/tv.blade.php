<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Modo TV · {{ $snapshot['name'] }}</title><link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/live.css') }}"><script defer src="{{ \App\Support\VersionedAsset::url('js/live.js') }}"></script><script defer src="{{ \App\Support\VersionedAsset::url('js/round-timer.js') }}"></script></head>
<body class="live-tv"><main><header><span>ManaLeague · Modo TV</span><h1>{{ $snapshot['name'] }}</h1><p>{{ $snapshot['format'] }}</p><button type="button" data-fullscreen>Tela cheia</button></header>
@include('arena.live-panel')
</main></body></html>
