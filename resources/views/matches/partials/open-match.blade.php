{{-- Partida em andamento: uma linha da lista (igual à do histórico) que abre ao clicar e mostra o que dá pra fazer agora:
     enviar o placar, abrir o contador de vida, confirmar ou contestar. O id mantém a linha aberta nas atualizações. --}}
@php($m = $row['match'])
@php($s = $row['submission'])
@php($meSide = ['name' => $row['me']?->displayName() ?? 'Você', 'profile' => $row['me']?->profile, 'me' => true])
@php($oppSide = ['name' => $row['opponent']?->displayName() ?? '', 'profile' => $row['opponent']?->profile, 'me' => false])
<details class="mm-open @if($row['needs_me']) is-needs-me @endif" id="partida-{{ $m->id }}">
    <summary class="ui-row mm-row is-{{ $row['tone'] }}">
        <span class="mm-round" aria-hidden="true">{{ $row['pod'] ? 'C' : match ($m->stage) { \App\Models\GameMatch::STAGE_FINAL => 'F', \App\Models\GameMatch::STAGE_THIRD_PLACE => '3º', default => 'R'.$m->round } }}</span>
        <span class="ui-row-main">
            <span class="ui-row-title">@include('matches.partials.players', ['row' => $row, 'plain' => true])</span>
            <span class="ui-row-sub">@if($row['tournament']->ranked)<span class="mm-kind is-ranked">Ranqueado</span> · @endif{{ $row['tournament']->name }} · {{ $row['stage'] }}</span>
        </span>
        <x-ui.badge :tone="$row['tone']">{{ $row['label'] }}</x-ui.badge>
        <x-icon name="chevron-down" :size="16" class="mm-toggle" />
    </summary>

    <div class="mm-body">
        @if($m->tournament->roundTimerRunning())<x-round-timer :ends-at="$m->tournament->round_ends_at" class="round-timer-compact" />@endif

        @if($row['action'] === 'submit')
            <form class="mm-score" method="POST" action="{{ route('matches.submissions.store', [$m->tournament, $m]) }}">@csrf
                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                @include('matches.partials.score-board', ['max' => 3, 'sides' => [
                    $meSide + ['field' => $row['iAmTwo'] ? 'player_two_games' : 'player_one_games'],
                    $oppSide + ['field' => $row['iAmTwo'] ? 'player_one_games' : 'player_two_games'],
                ]])
                <div class="mm-actions">
                    <button class="ui-btn ui-btn-primary"><x-icon name="circle-check" />Enviar resultado</button>
                    <a class="ui-btn ui-btn-outline" href="{{ route('life.tournament', [$m->tournament, $m]) }}"><x-icon name="heart" />Abrir contador de vida</a>
                </div>
                <p class="mm-help">Partidas vencidas por cada um. Pelo contador de vida, quando alguém zerar o placar já vem pronto.</p>
            </form>
        @elseif($row['action'] === 'review')
            <div class="mm-score">
            @include('matches.partials.score-board', ['sides' => [
                $meSide + ['value' => $row['my_score'], 'winner' => $row['my_score'] > $row['their_score']],
                $oppSide + ['value' => $row['their_score'], 'winner' => $row['their_score'] > $row['my_score']],
            ]])
            <p class="mm-claim">{{ $row['opponent']?->displayName() }} enviou o placar {{ $row['score'] }}: {{ $row['claim'] }}. Confira antes de confirmar.</p>
            <div class="mm-actions">
                <form method="POST" action="{{ route('matches.submissions.respond', $s) }}">@csrf<input type="hidden" name="action" value="confirm"><button class="ui-btn ui-btn-primary"><x-icon name="circle-check" />Confirmar placar</button></form>
                <details class="mm-dispute">
                    <summary class="ui-btn ui-btn-outline"><x-icon name="flag" />Contestar</summary>
                    <form method="POST" action="{{ route('matches.submissions.respond', $s) }}">@csrf<input type="hidden" name="action" value="dispute">
                        <label>Motivo da contestação<textarea name="reason" minlength="3" maxlength="1000" required placeholder="Ex.: foi 2–0, não 2–1."></textarea></label>
                        <button class="ui-btn ui-btn-outline ui-btn-sm">Enviar contestação</button>
                    </form>
                </details>
            </div>
            </div>
        @elseif($s)
            <div class="mm-score">
            @include('matches.partials.score-board', ['sides' => [
                $meSide + ['value' => $row['my_score'], 'winner' => $row['my_score'] > $row['their_score']],
                $oppSide + ['value' => $row['their_score'], 'winner' => $row['their_score'] > $row['my_score']],
            ]])
            <p class="mm-note">Placar enviado {{ $row['score'] }}: {{ $row['claim'] }}.@if($s->status === 'pending') {{ $row['opponent']?->displayName() }} ainda precisa confirmar.@endif</p>
            </div>
            @if($s->dispute_reason)<p class="mm-note">Contestação: {{ $s->dispute_reason }}</p>@endif
            @if($s->resolution_reason)<p class="mm-note">Resolução: {{ $s->resolution_reason }}</p>@endif
        @elseif($row['pod'])
            <p class="mm-note">{{ $row['note'] }}</p>
            <div class="mm-actions"><a class="ui-btn ui-btn-outline" href="{{ route('life.tournament', [$m->tournament, $m]) }}"><x-icon name="heart" />Contador de vida da mesa</a></div>
        @else
            <p class="mm-note">Aguardando o adversário desta rodada.</p>
        @endif
    </div>
</details>
