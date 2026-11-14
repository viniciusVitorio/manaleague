{{-- Desafio em andamento (em Desafios e em Minhas partidas): uma linha da lista que abre ao clicar e mostra o que dá
     para fazer agora: responder ao convite, enviar o placar, confirmar ou contestar. Não há página própria do desafio;
     encerrado, ele vira o resumo da partida em Minhas partidas. Desafio ranqueado usa a partida ranqueada dele.
     O id (#desafio-12) abre a linha quando um link aponta para ela e a mantém aberta nas atualizações. --}}
@php($c = $row['challenge'])
@php($match = $c->casualMatch)
@php($rm = $row['ranked'])
@php($opp = $row['opponent'])
@php($wins = intdiv($c->best_of, 2) + 1)
@php($tz = config('app.timezone'))
@php($claim = $row['my_score'] === null ? null : ($row['my_score'] > $row['their_score'] ? 'vitória sua' : ($row['my_score'] < $row['their_score'] ? 'vitória de '.$opp->displayName() : 'empate')))
@php($pending = $rm ? $rm->submissions->first() : null)
@php($meSide = ['name' => ($row['invited_me'] ? $c->opponent : $c->challenger)->displayName(), 'profile' => $row['invited_me'] ? $c->opponent : $c->challenger, 'me' => true])
@php($oppSide = ['name' => $opp->displayName(), 'profile' => $opp, 'me' => false])
@php($autoAt = ($rm ? $rm->autoConfirmAt($pending) : $match?->autoConfirmAt())?->timezone($tz)->format('d/m/Y \à\s H:i'))
<details class="mm-open @if($row['needs_me']) is-needs-me @endif" id="desafio-{{ $c->id }}">
    <summary class="ui-row mm-row is-{{ $row['tone'] ?? 'neutral' }}">
        <span class="mm-round" aria-hidden="true"><x-icon name="swords" :size="15" /></span>
        <span class="ui-row-main">
            <span class="ui-row-title">
                @if($c->status === 'pending')
                    <span class="mm-opp">{{ $row['invited_me'] ? $opp->displayName().' desafiou você' : 'Você desafiou '.$opp->displayName() }}</span>
                @else
                    <span class="mm-me">Você ×</span> <span class="mm-opp">{{ $opp->displayName() }}</span>
                @endif
            </span>
            <span class="ui-row-sub"><span class="mm-kind @if($c->is_ranked) is-ranked @endif">{{ $c->is_ranked ? 'Desafio ranqueado' : 'Desafio casual' }}</span> · {{ $c->format }} · MD{{ $c->best_of }}</span>
        </span>
        <x-ui.badge :tone="$row['tone'] ?? 'neutral'">{{ $row['label'] }}</x-ui.badge>
        <x-icon name="chevron-down" :size="16" class="mm-toggle" />
    </summary>

    <div class="mm-body">
        @if($c->status === 'pending')
            <p class="mm-note">Convite válido até {{ $c->expires_at->timezone($tz)->format('d/m/Y \à\s H:i') }}.@if($c->is_ranked) A série vale ELO.@endif</p>
            <form class="mm-actions" method="POST" action="{{ route('challenges.transition', $c) }}">@csrf
                @if($row['invited_me'])
                    <button class="ui-btn ui-btn-primary" name="action" value="accept"><x-icon name="circle-check" />Aceitar</button>
                    <button class="ui-btn ui-btn-outline" name="action" value="decline">Recusar</button>
                @else
                    <button class="ui-btn ui-btn-ghost ui-btn-sm" name="action" value="cancel" data-confirm="Cancelar o convite para {{ $opp->displayName() }}?">Cancelar convite</button>
                @endif
            </form>
        @elseif($c->is_ranked && ! $rm)
            <p class="mm-note">Este convite foi encerrado.</p>
        @elseif($row['state'] === 'reported' && ! $row['reported_by_me'])
            <div class="mm-score">
            @include('matches.partials.score-board', ['sides' => [
                $meSide + ['value' => $row['my_score'], 'winner' => $row['my_score'] > $row['their_score']],
                $oppSide + ['value' => $row['their_score'], 'winner' => $row['their_score'] > $row['my_score']],
            ]])
            <p class="mm-claim">{{ $opp->displayName() }} enviou o placar {{ $row['my_score'] }}–{{ $row['their_score'] }}: {{ $claim }}. Confira antes de confirmar; {{ $c->is_ranked ? 'a confirmação é definitiva e atualiza o ELO' : 'a confirmação é definitiva' }}.</p>
            <div class="mm-actions">
                @if($rm)
                    <form method="POST" action="{{ route('ranked.matches.confirm', $rm) }}">@csrf<button class="ui-btn ui-btn-primary"><x-icon name="circle-check" />Confirmar placar</button></form>
                @else
                    <form method="POST" action="{{ route('challenges.review', $c) }}">@csrf<input type="hidden" name="revision" value="{{ $match->revision }}"><input type="hidden" name="action" value="confirm"><button class="ui-btn ui-btn-primary"><x-icon name="circle-check" />Confirmar placar</button></form>
                @endif
                <details class="mm-dispute">
                    <summary class="ui-btn ui-btn-outline"><x-icon name="flag" />Contestar</summary>
                    <form method="POST" action="{{ $rm ? route('ranked.matches.dispute', $rm) : route('challenges.review', $c) }}">@csrf
                        @unless($rm)<input type="hidden" name="revision" value="{{ $match->revision }}"><input type="hidden" name="action" value="dispute">@endunless
                        <label>Motivo da contestação<textarea name="reason" minlength="3" maxlength="1000" required placeholder="Ex.: foi 2–0, não 2–1."></textarea></label>
                        <button class="ui-btn ui-btn-outline ui-btn-sm">Enviar contestação</button>
                    </form>
                </details>
            </div>
            @if($autoAt)<p class="mm-help">Você tem até {{ $autoAt }} para confirmar ou contestar. Depois disso, o placar é confirmado automaticamente.</p>@endif
            </div>
        @elseif($row['state'] === 'reported')
            <div class="mm-score">
            @include('matches.partials.score-board', ['sides' => [
                $meSide + ['value' => $row['my_score'], 'winner' => $row['my_score'] > $row['their_score']],
                $oppSide + ['value' => $row['their_score'], 'winner' => $row['their_score'] > $row['my_score']],
            ]])
            <p class="mm-note">Placar enviado {{ $row['my_score'] }}–{{ $row['their_score'] }}: {{ $claim }}. {{ $opp->displayName() }} ainda precisa confirmar.</p>
            @if($autoAt)<p class="mm-help">Se não houver resposta até {{ $autoAt }}, o placar é confirmado automaticamente.</p>@endif
            </div>
        @else
            @if($row['state'] === 'disputed')
                <p class="mm-note">{{ ! $rm && $match?->dispute_reason ? 'Placar contestado: '.$match->dispute_reason : 'Placar contestado' }}. Conversem e enviem o placar corrigido; uma nova confirmação será necessária.</p>
            @endif
            <form class="mm-score" method="POST" action="{{ $rm ? route('ranked.matches.results', $rm) : route('challenges.report', $c) }}">@csrf
                @if($rm)<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">@endif
                @php($mine = $rm ? ($row['i_am_one'] ? 'score_one' : 'score_two') : ($row['invited_me'] ? 'opponent_games' : 'challenger_games'))
                @php($theirs = $rm ? ($row['i_am_one'] ? 'score_two' : 'score_one') : ($row['invited_me'] ? 'challenger_games' : 'opponent_games'))
                @include('matches.partials.score-board', ['max' => $wins, 'sides' => [$meSide + ['field' => $mine], $oppSide + ['field' => $theirs]]])
                <div class="mm-actions">
                    <button class="ui-btn ui-btn-primary"><x-icon name="circle-check" />Enviar resultado</button>
                    @if($rm)
                        @if(Route::has('life.ranked'))<a class="ui-btn ui-btn-outline" href="{{ route('life.ranked', $rm) }}"><x-icon name="heart" />Abrir contador de vida</a>@endif
                    @else
                        <a class="ui-btn ui-btn-outline" href="{{ route('life.challenge', $c) }}"><x-icon name="heart" />Abrir contador de vida</a>
                    @endif
                </div>
                <p class="mm-help">Partidas vencidas por cada um. Pelo contador de vida, o placar e a ordem dos games já vêm prontos.</p>
            </form>
            @if($rm && $rm->status === 'pending')
                <form method="POST" action="{{ route('ranked.matches.cancel', $rm) }}">@csrf<button class="ui-btn ui-btn-ghost ui-btn-sm" data-confirm="Cancelar a partida ranqueada? Nada será pontuado.">Cancelar partida</button></form>
            @endif
        @endif
    </div>
</details>
