@php
// Escada de patentes: mostra onde o jogador esta e quanto falta para a proxima.
$groups = collect(\App\Services\Ranked\RankTierResolver::DEFAULT_DIVISIONS)->groupBy(1)->map(fn ($d) => ['key' => $d[0][2], 'min' => $d->min(3), 'max' => $d->last()[4], 'divisions' => $d->pluck(0)]);
$current = $tiers->resolve($rating);
$next = $groups->first(fn ($g) => $g['min'] > $rating);
@endphp
<div class="rank-ladder" role="list" aria-label="Progressão de patentes">
@foreach($groups as $name => $g)
    @php($state = $rating >= ($g['max'] ?? PHP_INT_MAX) + 1 ? 'done' : ($current['tier'] === $name ? 'current' : 'locked'))
    <div class="rank-step is-{{ $state }}" role="listitem" @if($state === 'current') aria-current="step" @endif>
        @include('ranked.partials.emblem', ['key' => $g['key'], 'size' => 36])
        <strong>{{ $name }}</strong>
        <small>{{ $g['max'] === null ? $g['min'].'+' : $g['min'].'–'.$g['max'] }}</small>
        @if($state === 'current')<span class="rank-step-here">{{ $current['division'] }}</span>@endif
    </div>
@endforeach
</div>
@if($next)<p class="muted rank-next">Faltam <strong>{{ $next['min'] - $rating }} pontos</strong> para {{ $groups->search($next) }} ({{ $rating }} / {{ $next['min'] }}).</p>@else<p class="muted rank-next">Você está no topo: Mestre.</p>@endif
