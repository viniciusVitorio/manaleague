<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>Conquista · ManaLeague</title><link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/app.css') }}"></head>
<body><main class="container page"><a href="{{ $public ? route('home') : route('profiles.index') }}">← ManaLeague</a><h1>Conquista desbloqueada</h1>
<article class="card section-card"><h2>{{ $achievement['icon'] }} {{ $achievement['name'] }}</h2><p>{{ $achievement['description'] }}</p><canvas id="achievement-card" width="1200" height="630" style="width:100%;max-width:800px;height:auto" aria-label="Imagem da conquista {{ $achievement['name'] }}" role="img"></canvas></article>
<div class="deck-actions"><button type="button" class="btn btn-primary" id="download-png">Baixar PNG</button><button type="button" class="btn btn-secondary" id="share-png">Compartilhar imagem</button></div><p id="share-feedback" role="status" aria-live="polite"></p>
@if(!$public)
<section class="card section-card"><h2>Link público opcional</h2><p>A imagem e o link exibem apenas a conquista, sem nome, e-mail, código de amigo ou dados do perfil. Quem receber o link poderá abri-lo. A revogação impede novos acessos, mas não apaga imagens já baixadas.</p>
@if(session('achievement_share_url'))<label>Copie seu link<input id="share-link" readonly value="{{ session('achievement_share_url') }}" onclick="this.select()"></label>@endif
<form method="POST" action="{{ route('achievements.share.store', $achievement['key']) }}">@csrf<button class="btn btn-secondary">{{ $active ? 'Gerar novo link e revogar anterior' : 'Ativar link público' }}</button></form>
@if($active)<form method="POST" action="{{ route('achievements.share.destroy', $achievement['key']) }}">@csrf @method('DELETE')<button class="btn btn-ghost">Revogar link público</button></form>@endif</section>
@endif
<script id="achievement-data" type="application/json">{!! json_encode($achievement, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
<script src="{{ \App\Support\VersionedAsset::url('js/achievement-share.js') }}" defer></script></main></body></html>
