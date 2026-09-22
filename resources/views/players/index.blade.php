@extends('layouts.app')
@section('content')
<header class="hero compact"><div><span class="eyebrow">Sua comunidade</span><h1>Jogadores</h1><p>Perfis criados automaticamente pelas inscrições. Marque seus amigos para adicioná-los rapidamente nos próximos torneios.</p></div></header>
<div class="profile-grid">
@forelse($profiles as $item)
@php($profile=$item['profile'])
<a class="card profile-card" href="{{ route('profiles.show', $profile) }}">
<div class="profile-head"><span class="profile-avatar">{{ $profile->avatar ?: mb_strtoupper(mb_substr($profile->nickname,0,1)) }}</span><div><h2>{{ $profile->nickname }}</h2><small>{{ $profile->is_friend ? '★ Amigo' : 'Jogador' }}</small></div></div>
<div class="mini-stats"><span><b>{{ $item['stats']['wins'] }}–{{ $item['stats']['losses'] }}</b> partidas</span><span><b>{{ $item['stats']['winrate'] }}%</b> aproveitamento</span><span><b>{{ $item['stats']['tournaments']->count() }}</b> torneios</span></div>
</a>
@empty<div class="empty-state"><h2>Nenhum jogador ainda</h2><p>Compartilhe um link de convite; o primeiro perfil aparecerá aqui.</p></div>@endforelse
</div>
@endsection
