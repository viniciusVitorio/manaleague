<section class="live-board" data-live-url="{{ route('arena.live',$slug) }}">
<p data-live-status role="status">Conectando ao placar ao vivo…</p>
<div class="live-columns"><section><h2>Classificação ao vivo</h2><ol data-live-standings>@foreach($snapshot['standings'] as $row)<li>{{ $row['name'] }} — {{ $row['points'] }} pts</li>@endforeach</ol></section>
<section><h2>Partidas ao vivo</h2><div data-live-matches>@foreach($snapshot['matches'] as $match)<p>R{{ $match['round'] }} · {{ $match['one'] }} {{ $match['one_score'] ?? '–' }} × {{ $match['two_score'] ?? '–' }} {{ $match['two'] }}</p>@endforeach</div></section></div>
<noscript>Ative JavaScript para atualização automática; recarregue para atualizar manualmente.</noscript></section>
