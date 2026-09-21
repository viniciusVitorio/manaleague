@php
    $champion = $final && $final->status === 'finished' ? ($final->player_one_games > $final->player_two_games ? $final->playerOne : $final->playerTwo) : null;
    $runnerUp = $final && $final->status === 'finished' ? ($final->player_one_games > $final->player_two_games ? $final->playerTwo : $final->playerOne) : null;
    $bronze = $thirdPlace && $thirdPlace->status === 'finished' ? ($thirdPlace->player_one_games > $thirdPlace->player_two_games ? $thirdPlace->playerOne : $thirdPlace->playerTwo) : null;
@endphp
@if($final)
<div class="podium-grid">
    <div class="podium second"><span>🥈</span><small>2º lugar</small><strong>{{ $runnerUp?->name ?? 'Em disputa' }}</strong></div>
    <div class="podium first"><span>🏆</span><small>Campeão</small><strong>{{ $champion?->name ?? 'Em disputa' }}</strong></div>
    <div class="podium third"><span>🥉</span><small>3º lugar</small><strong>{{ $bronze?->name ?? 'Em disputa' }}</strong></div>
</div>
@endif
