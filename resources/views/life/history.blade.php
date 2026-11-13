@extends('layouts.app')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/life-chart.css') }}"><script defer src="{{ \App\Support\VersionedAsset::url('js/life-chart.js') }}"></script>@endpush
@section('content')
{{-- Página do gráfico (link aberto sem JavaScript ou em nova aba); com JavaScript ele abre na janela da lista. --}}
<header class="page-head">
    <div>
        <span class="eyebrow">Histórico da partida</span>
        <h1>{{ implode(' × ', $chart['names']) }}</h1>
        <p>Vida de cada jogador ao longo de cada game, registrada pelo contador de vida.</p>
    </div>
</header>
<x-ui.card>
    @include('life.partials.chart', ['chart' => $chart])
</x-ui.card>
@endsection
