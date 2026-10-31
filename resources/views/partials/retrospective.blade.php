@if($recap)
<section class="card recap-card">
    <div class="recap-crown">🏆</div>
    <div><span class="eyebrow">Retrospectiva do campeonato</span><h2><x-profile-link :of="$recap['champion']">{{ $recap['champion']->name }}</x-profile-link> é o campeão de {{ $tournament->name }}</h2>
    <div class="recap-stats"><span><b>{{ $recap['wins'] }}–{{ $recap['losses'] }}</b> Recorde</span><span><b>{{ $recap['champion']->deckLabel() ?: 'Deck surpresa' }}</b> Deck</span><span><b>{{ $recap['gamesFor'] }}–{{ $recap['gamesAgainst'] }}</b> Games</span><span><b>{{ $recap['bestStreak'] }}</b> Maior sequência</span></div></div>
</section>
@endif
