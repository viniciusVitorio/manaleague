<div class="table-wrap">
<table class="standings">
    <thead><tr><th>#</th><th>Jogador / Deck</th><th>J</th><th>V</th><th>E</th><th>D</th><th>SG</th><th>PTS</th></tr></thead>
    <tbody>
    @forelse($ranking as $row)
        <tr class="{{ $row['rank'] <= 4 ? 'qualified' : '' }}">
            <td><span class="rank rank-{{ $row['rank'] }}">{{ $row['rank'] }}</span></td>
            <td><strong>{{ $row['player']->name }}</strong><small>{{ $row['player']->deck_name ?: 'Deck não informado' }} @if($row['player']->deck_colors) · {{ $row['player']->deck_colors }} @endif</small></td>
            <td>{{ $row['played'] }}</td><td>{{ $row['wins'] }}</td><td>{{ $row['draws'] }}</td><td>{{ $row['losses'] }}</td><td>{{ $row['game_diff'] > 0 ? '+' : '' }}{{ $row['game_diff'] }}</td><td><strong>{{ $row['points'] }}</strong></td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted">Cadastre os jogadores para formar a classificação.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<p class="table-note">Critérios: pontos, saldo de games, games vencidos e nome. Vitória/bye = 3 pts · empate = 1 pt.</p>
