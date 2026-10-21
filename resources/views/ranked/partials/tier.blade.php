@php($t = $tiers->resolve($rating))
<span class="tier-badge tier-{{ $t['key'] }}" title="{{ $t['division'] }} · {{ $rating }} ELO">@include('ranked.partials.emblem', ['key' => $t['key'], 'size' => 16]){{ $t['division'] }}</span>
