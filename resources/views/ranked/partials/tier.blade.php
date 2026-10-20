@php($t = $tiers->resolve($rating))
<span class="tier-badge tier-{{ $t['key'] }}" title="{{ $t['division'] }} · {{ $rating }} ELO"><span class="tier-gem" aria-hidden="true"></span>{{ $t['division'] }}</span>
