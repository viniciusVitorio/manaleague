<div class="table-wrap">
<table class="standings">
    <thead><tr><th>#</th><th>Jogador / Deck</th><th>J</th><th>V</th><th>E</th><th>D</th><th>SG</th><th>PTS</th></tr></thead>
    <tbody>
    @forelse($ranking as $row)
        <tr class="{{ $row['rank'] <= 4 ? 'qualified' : '' }}">
            <td><span class="rank rank-{{ $row['rank'] }}">{{ $row['rank'] }}</span></td>
            <td><strong><x-profile-link :of="$row['player']">{{ $row['player']->displayName() }}</x-profile-link></strong><x-streak :count="$row['streak']" /><small>@php($deck = $row['player']->deck)@if($deck && auth()->user()?->can('view', $deck))<a class="player-link" href="{{ route('decks.show', $deck) }}">{{ $deck->name }}</a>@else{{ $row['player']->deckLabel() ?: 'Deck não informado' }}@endif @if($row['player']->deckColors()) · {{ $row['player']->deckColors() }} @endif</small></td>
            <td>{{ $row['played'] }}</td><td>{{ $row['wins'] }}</td><td>{{ $row['draws'] }}</td><td>{{ $row['losses'] }}</td><td>{{ $row['game_diff'] > 0 ? '+' : '' }}{{ $row['game_diff'] }}</td><td><strong>{{ $row['points'] }}</strong></td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted">Cadastre os jogadores para formar a classificação.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<p class="table-note">Critérios: pontos, saldo de games, games vencidos e nome. Vitória/bye = 3 pts · empate = 1 pt.</p>
