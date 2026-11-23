@extends('layouts.app')
{{-- Boosters que o jogador ganhou em torneios: a receber primeiro, depois os recebidos. Atualiza quando o organizador marca a entrega. --}}
@section('live-scope', 'me')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/prizes.css') }}">@endpush
@section('content')
@php
    $pending = $inventory['pending'];
    $redeemed = $inventory['redeemed'];
    $sub = fn ($award) => implode(' · ', array_filter([$award->prize->tournament->name, $award->prize->placementLabel(), $award->prize->tournament->tournament_date?->format('d/m/Y')]));
@endphp
<div class="prize-narrow">
<header class="page-head">
    <div><h1>Meus prêmios</h1><p>Boosters que você ganhou em eventos. Troféus e medalhas ficam na estante do seu perfil.</p></div>
    @if($trophies)<div class="page-head-actions"><a class="ui-btn ui-btn-outline" href="{{ route('profiles.index') }}#trofeus"><x-icon name="trophy" />Ver estante <span class="count">{{ $trophies }}</span></a></div>@endif
</header>

@if($pending->isEmpty() && $redeemed->isEmpty())
    <x-ui.card>
        <x-ui.empty icon="gift" title="Nenhum booster ainda." text="Quando um evento com premiação encerrar, o que você ganhou aparece aqui." :href="route('tournaments.index')" action="Ver eventos" />
    </x-ui.card>
@else
    @if($pending->isNotEmpty())
        <x-ui.card title="A receber" icon="gift" :meta="$pending->count().' '.($pending->count() === 1 ? 'prêmio' : 'prêmios')" class="prize-list">
            <p class="prize-list-note">O organizador entrega na mesa. Marque quando estiver na sua mão; ele também pode marcar a entrega.</p>
            @foreach($pending as $award)
                <div class="ui-row prize-row">
                    <span class="ui-row-icon" aria-hidden="true"><x-icon name="gift" /></span>
                    <div class="ui-row-main"><span class="ui-row-title">{{ $award->prize->title() }}</span><span class="ui-row-sub">{{ $sub($award) }}</span></div>
                    <span class="ui-badge ui-badge-warning">A receber</span>
                    <form method="POST" action="{{ route('prizes.redeem', $award) }}" data-confirm="Marcar {{ $award->prize->title() }} como recebido? Depois disso só o organizador desfaz.">@csrf @method('PATCH')<button class="ui-btn ui-btn-outline ui-btn-sm">Já recebi</button></form>
                </div>
            @endforeach
        </x-ui.card>
    @endif
    @if($redeemed->isNotEmpty())
        <x-ui.card title="Recebidos" icon="circle-check" :meta="$redeemed->count().' '.($redeemed->count() === 1 ? 'prêmio' : 'prêmios')" class="prize-list">
            @foreach($redeemed as $award)
                <div class="ui-row prize-row">
                    <span class="ui-row-icon" aria-hidden="true"><x-icon name="gift" /></span>
                    <div class="ui-row-main"><span class="ui-row-title">{{ $award->prize->title() }}</span><span class="ui-row-sub">{{ $sub($award) }}</span></div>
                    <span class="ui-badge ui-badge-success">Recebido em {{ $award->redeemed_at->timezone(config('app.timezone'))->format('d/m/Y') }}</span>
                </div>
            @endforeach
        </x-ui.card>
    @endif
@endif
</div>
@endsection
