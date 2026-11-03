{{-- Linha do histórico de torneio: rodada, quem jogou, quando e o resultado do seu ponto de vista.
     $withTournament = true na linha do tempo (mostra o nome do torneio); false dentro do card do próprio torneio. --}}
@php($m = $row['match'])
<div class="ui-row mm-row is-{{ $row['tone'] }}">
    <span class="mm-round" aria-hidden="true">{{ $row['pod'] ? 'C' : match ($m->stage) { \App\Models\GameMatch::STAGE_FINAL => 'F', \App\Models\GameMatch::STAGE_THIRD_PLACE => '3º', default => 'R'.$m->round } }}</span>
    <span class="ui-row-main">
        <span class="ui-row-title">@include('matches.partials.players', ['row' => $row])</span>
        <span class="ui-row-sub">@if($withTournament ?? false)<span class="mm-kind @if($row['tournament']->ranked) is-ranked @endif">{{ $row['tournament']->ranked ? 'Torneio ranqueado' : 'Torneio' }}</span> · {{ $row['tournament']->name }} · @endif{{ $row['stage'] }}@if($row['date']) · {{ $row['date']->timezone(config('app.timezone'))->format('d/m/Y') }}@endif @if($row['note'])· {{ $row['note'] }}@endif</span>
    </span>
    <x-ui.badge :tone="$row['tone']">{{ $row['label'] }}</x-ui.badge>
</div>
