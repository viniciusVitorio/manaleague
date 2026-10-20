@props(['tone' => 'neutral'])
{{-- tone: neutral | info | success | warning | danger --}}
<span {{ $attributes->merge(['class' => 'ui-badge ui-badge-'.$tone]) }}>{{ $slot }}</span>
