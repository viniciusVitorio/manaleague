@extends('layouts.app')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/life-chart.css') }}"><link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/match-detail.css') }}"><script defer src="{{ \App\Support\VersionedAsset::url('js/life-chart.js') }}"></script>@endpush
@section('content')
{{-- Resumo da partida aberto direto (nova aba ou sem JavaScript); com JavaScript ele abre na janela de Minhas partidas. --}}
<header class="page-head">
    <div>
        <span class="eyebrow">Partida</span>
        <h1>{{ collect($detail['players'])->pluck('name')->implode(' × ') }}</h1>
    </div>
    <div class="page-head-actions">
        <a class="ui-btn ui-btn-outline" href="{{ route('matches.mine') }}">Minhas partidas</a>
    </div>
</header>
<x-ui.card>
    @include('matches.partials.detail', ['detail' => $detail])
</x-ui.card>
@endsection
