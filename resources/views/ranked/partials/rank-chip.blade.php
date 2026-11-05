@php
    // Selo do ranque no topo do perfil: emblema, divisão e ELO do melhor formato; leva à seção de ranque.
    $t = $tiers->resolve($rating);
@endphp
<a class="rank-chip tier-{{ $t['key'] }}" href="#ranque" title="Ver ranque">@include('ranked.partials.emblem', ['key' => $t['key'], 'size' => 22])<strong>{{ $t['division'] }}</strong><span>{{ $rating }} ELO · {{ $format }}</span></a>
