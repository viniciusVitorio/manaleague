@php
    // Card estilo LoL: emblema grande + divisao + PDL. Sem partidas = "Sem ranque" (mostra a divisao inicial esmaecida).
    $t = $tiers->resolve($rating);
    $unranked = $record === null;
    $nextDivision = collect(\App\Services\Ranked\RankTierResolver::DEFAULT_DIVISIONS)->first(fn ($d) => $d[3] > $rating);
@endphp
<article @class(['rank-card', 'tier-'.$t['key'], 'is-unranked' => $unranked])>
    <div class="rank-card-emblem">@include('ranked.partials.emblem', ['key' => $t['key'], 'size' => 76])</div>
    <div class="rank-card-body">
        <span class="rank-card-label">{{ $label }}</span>
        <strong class="rank-card-division">{{ $unranked ? 'Sem ranque' : $t['division'] }}</strong>
        <span class="rank-card-pdl">
            @if($unranked)Começa em {{ $t['division'] }} · {{ $rating }} ELO
            @else{{ $t['pdl'] }}@if($t['pdl_max'])/{{ $t['pdl_max'] }}@endif PDL · {{ $rating }} ELO @if($record->isProvisional())<span class="provisional" title="Primeiras partidas da temporada">Provisório</span>@endif
            @endif
        </span>
        @if($t['pdl_max'])<div class="pdl" role="progressbar" aria-label="PDL na divisão" aria-valuemin="0" aria-valuemax="{{ $t['pdl_max'] }}" aria-valuenow="{{ $unranked ? 0 : $t['pdl'] }}"><span style="width: {{ $unranked ? 0 : round($t['pdl'] * 100 / $t['pdl_max']) }}%"></span></div>@endif
        @unless($unranked)
            <small class="muted">{{ $record->wins }}V {{ $record->losses }}D{{ $record->draws ? ' '.$record->draws.'E' : '' }}@if($nextDivision) · faltam {{ $nextDivision[3] - $rating }} para {{ $nextDivision[0] }}@endif</small>
        @endunless
    </div>
</article>
