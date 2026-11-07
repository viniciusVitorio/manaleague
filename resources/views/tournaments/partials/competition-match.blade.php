@php
    // Card de partida: os dois jogadores em linhas, com o placar ao lado de cada um (como um placar de TV).
    // Quem organiza lança o resultado direto nas linhas; resultado já lançado fica em "Corrigir placar".
    // Espera $match e $tournament; opcionais: $manage (pode editar), $live (rodada atual), $table (número da mesa).
    $manage = $manage ?? false;
    $live = $live ?? false;
    $table = $table ?? null;
    $editable = $manage && app(\App\Services\MatchResultService::class)->editable($tournament, $match);
    $stageLabel = match ($match->bracket) {
        'winners' => 'Chave principal', 'losers' => 'Repescagem', 'grand_final' => 'Grande final', 'reset_final' => 'Final de desempate', 'final' => 'Final',
        default => match ($match->stage) { 'final' => 'Final', 'third_place' => 'Disputa de 3º', default => null },
    };
    $isBye = $match->status === 'bye';
    $isFinished = $match->status === 'finished';
    $stateLabel = $isBye ? 'Folga' : ($isFinished ? 'Encerrada' : ($live ? 'Em jogo' : 'A jogar'));
    $oneWins = $isFinished && $match->player_one_games > $match->player_two_games;
    $twoWins = $isFinished && $match->player_two_games > $match->player_one_games;
    $one = $match->playerOne;
    $two = $match->playerTwo;
@endphp
<article @class(['match-card', 'tmatch', 'is-'.$match->status, 'decisive' => $match->stage !== 'league']) id="match-{{ $match->id }}">
    <header class="tmatch-head">
        @if($table)<span class="tmatch-table">Mesa {{ $table }}</span>@endif
        @if($stageLabel)<span class="tmatch-stage">{{ $stageLabel }}</span>@endif
        <span class="tmatch-state is-{{ $isBye ? 'bye' : ($isFinished ? 'done' : ($live ? 'live' : 'next')) }}">{{ $stateLabel }}</span>
    </header>
    @if($match->pod_player_ids)
        <form class="pod-score-form" method="POST" action="{{ route('matches.update', [$tournament, $match]) }}">@csrf @method('PUT')
            @foreach($match->pod_player_ids as $id)
                @php($podPlayer = $tournament->players->firstWhere('id', $id))
                <label>{{ $podPlayer?->displayName() }}<select name="placements[{{ $id }}]" aria-label="Posição de {{ $podPlayer?->name }}" @disabled(! $editable)><option value="">Posição</option>@foreach(range(1, count($match->pod_player_ids)) as $rank)<option value="{{ $rank }}" @selected(($match->pod_scores[$id] ?? null) === $rank)>{{ $rank }}º</option>@endforeach</select></label>
            @endforeach
            @if($editable)
                <div class="tmatch-actions"><button class="ui-btn ui-btn-primary ui-btn-sm">{{ $isFinished ? 'Corrigir posições' : 'Salvar posições' }}</button>@if($match->status === 'pending')<a class="ui-btn ui-btn-ghost ui-btn-sm" href="{{ route('life.tournament', [$tournament, $match]) }}"><x-icon name="heart" />Contador</a>@endif</div>
            @endif
        </form>
    @elseif($editable && $match->status === 'pending')
        {{-- Lançar resultado: o placar de cada jogador fica na linha dele --}}
        <form class="tmatch-form" method="POST" action="{{ route('matches.update', [$tournament, $match]) }}">@csrf @method('PUT')
            <div class="tmatch-rows">
                <label class="tmatch-row"><span class="tmatch-name">{{ $one->displayName() }}</span><input type="number" inputmode="numeric" name="player_one_games" min="0" max="3" value="{{ $match->player_one_games ?? 0 }}" aria-label="Games de {{ $one->displayName() }}"></label>
                <label class="tmatch-row"><span class="tmatch-name">{{ $two?->displayName() }}</span><input type="number" inputmode="numeric" name="player_two_games" min="0" max="3" value="{{ $match->player_two_games ?? 0 }}" aria-label="Games de {{ $two?->displayName() }}"></label>
            </div>
            <div class="tmatch-actions">
                <button class="ui-btn ui-btn-primary ui-btn-sm">Salvar resultado</button>
                @if($two)<a class="ui-btn ui-btn-ghost ui-btn-sm" href="{{ route('life.tournament', [$tournament, $match]) }}"><x-icon name="heart" />Contador de vida</a>@endif
            </div>
        </form>
    @else
        <div class="tmatch-rows">
            <div @class(['tmatch-row', 'is-winner' => $oneWins, 'is-loser' => $twoWins])><span class="tmatch-name"><x-profile-link :of="$one">{{ $one->displayName() }}</x-profile-link></span><b class="tmatch-score">{{ $match->player_one_games ?? '–' }}</b></div>
            <div @class(['tmatch-row', 'is-winner' => $twoWins, 'is-loser' => $oneWins])><span class="tmatch-name">@if($two)<x-profile-link :of="$two">{{ $two->displayName() }}</x-profile-link>@else<span class="muted">Folga</span>@endif</span><b class="tmatch-score">{{ $match->player_two_games ?? '–' }}</b></div>
        </div>
        @if($isBye)
            <p class="tmatch-note">Vitória automática por 2×0.</p>
        @elseif($editable)
            <details class="tmatch-fix">
                <summary>Corrigir placar</summary>
                <form class="tmatch-fix-form" method="POST" action="{{ route('matches.update', [$tournament, $match]) }}">@csrf @method('PUT')
                    <input type="number" inputmode="numeric" name="player_one_games" min="0" max="3" value="{{ $match->player_one_games ?? 0 }}" aria-label="Games de {{ $one->displayName() }}">
                    <span aria-hidden="true">×</span>
                    <input type="number" inputmode="numeric" name="player_two_games" min="0" max="3" value="{{ $match->player_two_games ?? 0 }}" aria-label="Games de {{ $two?->displayName() }}">
                    <button class="ui-btn ui-btn-outline ui-btn-sm">Corrigir</button>
                </form>
            </details>
        @elseif($manage && $isFinished)
            <p class="tmatch-note" title="Uma fase seguinte já foi gerada ou o torneio foi encerrado.">Resultado travado.</p>
        @endif
    @endif
</article>
