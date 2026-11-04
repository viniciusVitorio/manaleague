@php
    // Card estilo LoL: emblema grande, divisão e ELO em destaque, barra de PDL e o retrospecto.
    // Sem partidas = "Sem ranque" (mostra a divisão inicial esmaecida).
    $t = $tiers->resolve($rating);
    $unranked = $record === null;
    $nextDivision = collect(\App\Services\Ranked\RankTierResolver::DEFAULT_DIVISIONS)->first(fn ($d) => $d[3] > $rating);
    $played = $unranked ? 0 : $record->wins + $record->losses + $record->draws;
    $winRate = $played ? (int) round($record->wins * 100 / $played) : null;
@endphp
<article @class(['rank-card', 'tier-'.$t['key'], 'is-unranked' => $unranked])>
    <div class="rank-card-top">
        <span class="rank-card-label">{{ $label }}</span>
        @if(! $unranked && $record->isProvisional())<span class="provisional" title="Primeiras partidas da temporada: o ELO ainda varia mais">Provisório</span>@endif
    </div>
    <div class="rank-card-main">
        <div class="rank-card-emblem">@include('ranked.partials.emblem', ['key' => $t['key'], 'size' => 88])</div>
        <div class="rank-card-title">
            <strong class="rank-card-division">{{ $unranked ? 'Sem ranque' : $t['division'] }}</strong>
            @if($unranked)
                <span class="rank-card-elo">Começa em {{ $t['division'] }} · {{ $rating }} ELO</span>
            @else
                <span class="rank-card-elo"><b>{{ $rating }}</b> ELO</span>
            @endif
        </div>
    </div>
    @if($t['pdl_max'])
        <div class="rank-card-progress">
            <div class="rank-card-progress-line">
                <span>{{ $unranked ? 0 : $t['pdl'] }}/{{ $t['pdl_max'] }} PDL</span>
                @if(! $unranked && $nextDivision)<span>faltam {{ $nextDivision[3] - $rating }} para {{ $nextDivision[0] }}</span>@endif
            </div>
            <div class="pdl" role="progressbar" aria-label="PDL na divisão" aria-valuemin="0" aria-valuemax="{{ $t['pdl_max'] }}" aria-valuenow="{{ $unranked ? 0 : $t['pdl'] }}"><span style="width: {{ $unranked ? 0 : round($t['pdl'] * 100 / $t['pdl_max']) }}%"></span></div>
        </div>
    @elseif(! $unranked)
        <p class="rank-card-progress-line">{{ $t['pdl'] }} PDL acima de Mestre · patente máxima</p>
    @endif
    @unless($unranked)
        <dl class="rank-card-stats">
            <div><dt>Vitórias</dt><dd>{{ $record->wins }}</dd></div>
            <div><dt>Derrotas</dt><dd>{{ $record->losses }}</dd></div>
            @if($record->draws)<div><dt>Empates</dt><dd>{{ $record->draws }}</dd></div>@endif
            <div><dt>Aproveitamento</dt><dd>{{ $winRate === null ? '–' : $winRate.'%' }}</dd></div>
        </dl>
    @endunless
</article>
