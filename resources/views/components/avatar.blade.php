@props(['of' => null])
{{-- Circulo de avatar: foto do perfil quando houver; senao o emoji ou a inicial. $of = PlayerProfile|User|null --}}
@php
    $avatarUrl = $of?->avatarUrl();
    $avatarMark = $of instanceof \App\Models\User ? $of->avatarMark() : ($of?->mark() ?? '?');
@endphp
<span {{ $attributes->class(['has-photo' => $avatarUrl !== null]) }}>@if($avatarUrl)<img src="{{ $avatarUrl }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">@else{{ $avatarMark }}@endif</span>
