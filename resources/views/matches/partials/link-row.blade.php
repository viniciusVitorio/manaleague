{{-- Desafio encerrado (casual ou ranqueado) ou partida Ranked do matchmaking: no histórico, a linha abre o resumo da
     partida na janela; Ranked em andamento leva à tela da partida, onde ficam as ações. Desafio em andamento usa o
     cartão com as ações (challenges/partials/open-challenge). Por isso o nome do adversário é texto, não link de perfil. --}}
@php($isChallenge = $row['kind'] === 'challenge')
@php($c = $isChallenge ? $row['challenge'] : null)
@php($rm = $isChallenge ? null : $row['ranked'])
<a class="ui-row mm-row mm-link is-{{ $row['tone'] }} @if($row['needs_me']) needs-me @endif" href="{{ $row['detailUrl'] ?? $row['href'] }}"@if(! empty($row['detailUrl'])) data-dialog-open="match-dialog" data-dialog-src @endif>
    <span class="mm-round" aria-hidden="true"><x-icon :name="$isChallenge ? 'swords' : 'medal'" :size="15" /></span>
    <span class="ui-row-main">
        <span class="ui-row-title">
            @if($isChallenge && $c->status === 'pending')
                <span class="mm-opp">{{ $row['invited_me'] ? $row['opponent']->displayName().' desafiou você' : 'Você desafiou '.$row['opponent']->displayName() }}</span>
            @else
                <span class="mm-me">Você ×</span> <span class="mm-opp">{{ $row['opponent']?->displayName() }}</span>
            @endif
        </span>
        <span class="ui-row-sub">
            @if($isChallenge)
                <span class="mm-kind @if($c->is_ranked) is-ranked @endif">{{ $c->is_ranked ? 'Desafio ranqueado' : 'Desafio casual' }}</span> · {{ $c->format }} · MD{{ $c->best_of }}
            @else
                <span class="mm-kind is-ranked">Ranked</span> · {{ $rm->format }} · MD{{ $rm->best_of }}
            @endif
            @if($row['date']) · {{ $row['date']->timezone(config('app.timezone'))->format('d/m/Y') }}@endif
        </span>
    </span>
    <x-ui.badge :tone="$row['tone']">{{ $row['label'] }}</x-ui.badge>
    <x-icon name="chevron-right" :size="16" class="mm-go" />
</a>
