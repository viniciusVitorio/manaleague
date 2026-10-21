@php($current = $tiers->resolve($rating))
<details class="all-tiers">
    <summary><x-icon name="chart" />Ver todas as patentes e divisões</summary>
    <p class="muted">Cada patente tem duas divisões de 100 pontos (II e depois I). Subir ou cair é na hora, sem série de promoção. Todos começam em <strong>{{ (int) config('ranked.defaults.starting_rating') }} ELO</strong> a cada temporada.</p>
    <table class="all-tiers-table">
        <thead><tr><th scope="col">Patente</th><th scope="col">Divisão</th><th scope="col">ELO</th></tr></thead>
        <tbody>
        @foreach(array_reverse(\App\Services\Ranked\RankTierResolver::DEFAULT_DIVISIONS) as [$division, $tier, $key, $min, $max])
            <tr @class(['is-current' => $current['division'] === $division])>
                <td><span class="all-tiers-name">@include('ranked.partials.emblem', ['key' => $key, 'size' => 22]){{ $tier }}</span></td>
                <td>{{ $division }}@if($current['division'] === $division) <span class="rank-step-here">você</span>@endif</td>
                <td class="num">{{ $max === null ? $min.'+' : $min.' – '.$max }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</details>
