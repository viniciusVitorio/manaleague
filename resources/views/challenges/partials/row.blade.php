{{-- Uma linha da lista de desafios: adversário, tipo da série e o estado (resultado ou o que falta fazer). Linha inteira leva ao desafio. --}}
@php($c = $row['challenge'])
<a class="ui-row challenge-row @if($row['needs_me']) needs-me @endif" href="{{ route('challenges.show', $c) }}">
    <x-avatar :of="$row['opponent']" class="ui-avatar" aria-hidden="true" />
    <span class="ui-row-main">
        <span class="ui-row-title">{{ $row['invited_me'] ? $row['opponent']->nickname.' desafiou você' : 'Você desafiou '.$row['opponent']->nickname }}</span>
        <span class="ui-row-sub">@if($c->is_ranked)<b class="challenge-ranked">Ranqueada</b> · @endif{{ $c->format }} · MD{{ $c->best_of }} · {{ $c->created_at->timezone(config('app.timezone'))->format('d/m/Y') }}</span>
    </span>
    <span class="ui-badge @if($row['tone']) ui-badge-{{ $row['tone'] }} @endif">{{ $row['label'] }}</span>
    <x-icon name="chevron-right" :size="16" class="challenge-row-go" />
</a>
