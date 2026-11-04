{{-- Torneio encerrado: pódio (2º, 1º no meio, 3º) com foto e nome em cima de cada degrau, e os números do campeão. --}}
{{-- Espera $tournament, $recap e $ranking já com o pódio marcado. --}}
@php
    // O pódio vem da classificação final (StandingsService::withPodium), a mesma que pinta as faixas da tabela.
    $podium = $ranking->filter(fn ($row) => ! empty($row['podium']))->keyBy('podium');
    $champion = $recap['champion'] ?? ($podium[1]['player'] ?? null);
    $steps = [1 => $champion, 2 => $podium[2]['player'] ?? null, 3 => $podium[3]['player'] ?? null];
    $placeLabel = [1 => 'Campeão', 2 => '2º lugar', 3 => '3º lugar'];
@endphp
@if($champion)
<section class="card tourney-champion" aria-labelledby="champion-title">
    <header class="champion-head">
        <span class="eyebrow"><x-icon name="trophy" :size="13" />Retrospectiva do campeonato</span>
        <h2 id="champion-title"><x-profile-link :of="$champion">{{ $champion->name }}</x-profile-link> é o campeão de {{ $tournament->name }}</h2>
    </header>
    <div class="champion-stage">
        {{-- Na leitura, 1º, 2º e 3º; na tela, o 1º fica no meio (order no CSS). --}}
        <ol class="podium-steps">
            @foreach($steps as $place => $player)
                <li class="podium-step is-{{ $place }}">
                    <div class="podium-person">
                        @if($player)
                            @if($place === 1)<x-icon name="crown" class="podium-crown" />@endif
                            @if($player->profile)<x-avatar :of="$player->profile" class="ui-avatar podium-avatar" aria-hidden="true" />@else<span class="ui-avatar podium-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($player->name, 0, 1)) }}</span>@endif
                            <strong class="podium-name"><x-profile-link :of="$player">{{ $player->name }}</x-profile-link></strong>
                            <span class="sr-only">{{ $placeLabel[$place] }}</span>
                            @if($player->deckLabel())<small class="podium-deck">{{ $player->deckLabel() }}</small>@endif
                        @endif
                    </div>
                    <div class="podium-block" aria-hidden="true"><span>{{ $place }}</span></div>
                </li>
            @endforeach
        </ol>
    </div>
    @if($recap)
        <dl class="champion-stats">
            <div><dt>Recorde</dt><dd>{{ $recap['wins'] }}–{{ $recap['losses'] }}</dd></div>
            <div><dt>Deck</dt><dd>{{ $recap['champion']->deckLabel() ?: 'Deck surpresa' }}</dd></div>
            <div><dt>Games</dt><dd>{{ $recap['gamesFor'] }}–{{ $recap['gamesAgainst'] }}</dd></div>
            <div><dt>Maior sequência</dt><dd>{{ $recap['bestStreak'] }} {{ $recap['bestStreak'] === 1 ? 'vitória' : 'vitórias' }}</dd></div>
        </dl>
    @endif
</section>
@endif
