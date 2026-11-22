@php
    $editable = ($manage ?? false) && app(\App\Services\MatchResultService::class)->editable($tournament, $match);
    $confirmed = $match->pod_confirmed_at !== null;
    $scores = $match->pod_tiebreak_scores ?: ($match->pod_scores ?? []);
@endphp
<article class="match-card tmatch pod-elimination" id="match-{{ $match->id }}">
    <header class="tmatch-head"><span class="tmatch-table">Mesa {{ $match->pod_number }}</span><span class="tmatch-stage">{{ $match->podPhaseLabel() }}</span><x-ui.badge :tone="$confirmed ? 'success' : ($match->pod_scores ? 'warning' : 'info')">{{ $confirmed ? 'Confirmada' : ($match->pod_scores ? 'Aguardando confirmação' : (($live ?? false) ? 'Em andamento' : 'Pendente')) }}</x-ui.badge></header>
    @if($match->stage === 'consolation')<p class="tmatch-note">Define o 5º ao 8º lugar.</p>@endif
    @if($editable)
    <form method="POST" action="{{ route('matches.update', [$tournament, $match]) }}" class="pod-elimination-form">@csrf @method('PUT')
    @endif
    <div class="tmatch-rows">
    @foreach($match->pod_player_ids as $pid)
        @php
            $seat = $tournament->players->firstWhere('id', $pid);
            $place = $scores[$pid] ?? null;
            $advances = $confirmed && $match->stage === 'league' && $place <= $tournament->pod_qualifiers;
        @endphp
        <div @class(['pod-elimination-seat', 'is-qualified' => $advances])>
            <div class="pod-elimination-name"><x-profile-link :of="$seat">{{ $seat?->displayName() }}</x-profile-link>
                @if($advances)<x-ui.badge tone="success">Classificado</x-ui.badge>
                @elseif($confirmed && $match->stage === 'league')<x-ui.badge>Eliminado</x-ui.badge>
                @elseif($confirmed && $match->stage === 'final' && $place === 1)<x-ui.badge tone="success">Campeão da final</x-ui.badge>
                @endif
            </div>
            @if($editable)
                <label><span class="sr-only">Posição de {{ $seat?->displayName() }}</span><select name="placements[{{ $pid }}]" required><option value="">Posição</option>@foreach(range(1, count($match->pod_player_ids)) as $rank)<option value="{{ $rank }}" @selected(($match->pod_scores[$pid] ?? null) === $rank)>{{ $rank }}º</option>@endforeach</select></label>
            @else
                <strong>{{ $confirmed ? $place.'º' : '–' }}</strong>
            @endif
        </div>
    @endforeach
    </div>
    @if($editable)
        <p class="tmatch-note">Empates são permitidos: use posições compartilhadas (1, 2, 2, 4). O organizador confirma o resultado da mesa. Vagas empatadas e o título precisam de desempate explícito. Prêmios de colocação empatada ficam sem destinatário; resolva o empate antes de confirmar a final se houver premiação para essa posição.</p>
        <details class="pod-elimination-tiebreak" @if($match->pod_tiebreak_scores) open @endif><summary>Resolver empate · {{ $tournament->pod_tiebreak === 'playoff' ? 'partida de desempate' : 'decisão documentada' }}</summary>
            <p class="tmatch-note">Preencha todos os lugares. Só reordene jogadores do mesmo grupo empatado; mantenha as demais posições.</p>
            @foreach($match->pod_player_ids as $pid)
                @php($seat = $tournament->players->firstWhere('id', $pid))
                <label>{{ $seat?->displayName() }}<select name="tiebreak[{{ $pid }}]"><option value="">Sem desempate</option>@foreach(range(1, count($match->pod_player_ids)) as $rank)<option value="{{ $rank }}" @selected(($match->pod_tiebreak_scores[$pid] ?? null) === $rank)>{{ $rank }}º</option>@endforeach</select></label>
            @endforeach
            <label>Justificativa do desempate<textarea name="resolution_note" maxlength="1000">{{ $match->pod_resolution_note }}</textarea></label>
        </details>
        <div class="tmatch-actions"><button class="ui-btn ui-btn-outline ui-btn-sm" name="confirm" value="0">Salvar provisório</button><button class="ui-btn ui-btn-outline ui-btn-sm" name="confirm" value="1">Confirmar posições</button>
        @if(!$confirmed)<a class="ui-btn ui-btn-ghost ui-btn-sm" href="{{ route('life.tournament', [$tournament, $match]) }}"><x-icon name="heart" />Contador</a>@endif</div>
    </form>
    @else
        <p class="tmatch-note">{{ $confirmed ? 'Resultado confirmado pelo organizador.' : 'O organizador registra e confirma as posições.' }}</p>
        @if($match->pod_tiebreak_scores)<p class="tmatch-note">Desempate registrado; posições originais compartilhadas preservadas no histórico.</p>@endif
    @endif
</article>
