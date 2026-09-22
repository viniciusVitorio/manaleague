@if($recap)
<section class="card recap-card">
    <div class="recap-crown">🏆</div>
    <div><span class="eyebrow">Retrospectiva do campeonato</span><h2>{{ $recap['champion']->name }} é o campeão de {{ $tournament->name }}</h2>
    <div class="recap-stats"><span><b>{{ $recap['wins'] }}–{{ $recap['losses'] }}</b> Recorde</span><span><b>{{ $recap['champion']->deck_name ?: 'Deck surpresa' }}</b> Deck</span><span><b>{{ $recap['gamesFor'] }}–{{ $recap['gamesAgainst'] }}</b> Games</span><span><b>{{ $recap['bestStreak'] }}</b> Maior sequência</span></div></div>
</section>
@endif
