{{-- Linha do histórico de torneio: rodada, quem jogou, quando e o resultado do seu ponto de vista.
     $withTournament = true na linha do tempo (mostra o nome do torneio); false dentro do card do próprio torneio.
     Partida encerrada: a linha inteira abre o resumo (decks, placar e gráfico) na janela; por isso os nomes ficam sem link. --}}
@php($m = $row['match'])
@php($detailUrl = $row['detailUrl'] ?? null)
@if($detailUrl)
<a class="ui-row mm-row mm-link is-{{ $row['tone'] }}" href="{{ $detailUrl }}" data-dialog-open="match-dialog" data-dialog-src>
@else
<div class="ui-row mm-row is-{{ $row['tone'] }}">
@endif
    <span class="mm-round" aria-hidden="true">{{ $row['pod'] ? 'C' : match ($m->stage) { \App\Models\GameMatch::STAGE_FINAL => 'F', \App\Models\GameMatch::STAGE_THIRD_PLACE => '3º', default => 'R'.$m->round } }}</span>
    <span class="ui-row-main">
        <span class="ui-row-title">@include('matches.partials.players', ['row' => $row, 'plain' => (bool) $detailUrl])</span>
        <span class="ui-row-sub">@if($withTournament ?? false)<span class="mm-kind @if($row['tournament']->ranked) is-ranked @endif">{{ $row['tournament']->ranked ? 'Torneio ranqueado' : 'Torneio' }}</span> · {{ $row['tournament']->name }} · @endif{{ $row['stage'] }}@if($row['date']) · {{ $row['date']->timezone(config('app.timezone'))->format('d/m/Y') }}@endif @if($row['note'])· {{ $row['note'] }}@endif</span>
    </span>
    <x-ui.badge :tone="$row['tone']">{{ $row['label'] }}</x-ui.badge>
@if($detailUrl)
    <x-icon name="chevron-right" :size="16" class="mm-go" />
</a>
@else
</div>
@endif
