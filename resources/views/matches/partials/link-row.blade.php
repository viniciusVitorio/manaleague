{{-- Desafio (casual ou ranqueado) ou partida Ranked do matchmaking. A linha inteira leva à tela do desafio/partida,
     onde ficam as ações (enviar, confirmar, contestar); por isso o nome do adversário é texto, não link de perfil. --}}
@php($isChallenge = $row['kind'] === 'challenge')
@php($c = $isChallenge ? $row['challenge'] : null)
@php($rm = $isChallenge ? null : $row['ranked'])
<a class="ui-row mm-row mm-link is-{{ $row['tone'] }} @if($row['needs_me']) needs-me @endif" href="{{ $row['href'] }}">
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
