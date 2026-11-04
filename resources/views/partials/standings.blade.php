<div class="table-wrap">
<table class="standings">
    <thead><tr><th>#</th><th>Jogador</th><th>J</th><th>V</th><th>E</th><th>D</th><th>SG</th><th>PTS</th></tr></thead>
    <tbody>
    @forelse($ranking as $row)
        @php($place = $row['podium'] ?? null)
        {{-- Torneio encerrado: campeão, vice e 3º ganham faixa de ouro, prata e bronze --}}
        <tr @class(['qualified' => $row['rank'] <= 4, 'is-podium is-podium-'.$place => $place])>
            <td><span class="rank rank-{{ $row['rank'] }}">{{ $row['rank'] }}</span></td>
            @php($deck = $row['player']->deck)
            @php($deckLink = $deck && auth()->user()?->can('view', $deck))
            {{-- Sem deck informado, a linha fica só com o nome (antes repetia "Deck não informado" em todo mundo) --}}
            <td><strong><x-profile-link :of="$row['player']">{{ $row['player']->displayName() }}</x-profile-link></strong><x-streak :count="$row['streak']" />@if($place)<span class="standings-medal">{{ [1 => 'Campeão', 2 => 'Vice', 3 => '3º lugar'][$place] }}</span>@endif @if($deckLink || $row['player']->deckLabel())<small>@if($deckLink)<a class="player-link" href="{{ route('decks.show', $deck) }}">{{ $deck->name }}</a>@else{{ $row['player']->deckLabel() }}@endif @if($row['player']->deckColors()) · {{ $row['player']->deckColors() }} @endif</small>@endif</td>
            <td>{{ $row['played'] }}</td><td>{{ $row['wins'] }}</td><td>{{ $row['draws'] }}</td><td>{{ $row['losses'] }}</td><td>{{ $row['game_diff'] > 0 ? '+' : '' }}{{ $row['game_diff'] }}</td><td><strong>{{ $row['points'] }}</strong></td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted">Cadastre os jogadores para formar a classificação.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<p class="table-note">@if($ranking->contains(fn ($row) => isset($row['podium'])) && $tournament->matches->contains('stage', 'final'))Pódio pela final e pela disputa de 3º lugar; o resto segue a liga. @endif Critérios: pontos, saldo de games, games vencidos e nome. Vitória/bye = 3 pts · empate = 1 pt.</p>
