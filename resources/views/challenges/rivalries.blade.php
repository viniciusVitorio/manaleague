@extends('layouts.app')
@section('content')
<a class="back-link" href="{{ route('challenges.index') }}">← Desafios</a><h1>Rivalidades casuais</h1><p>Somente séries confirmadas. Estes números não alteram suas estatísticas competitivas.</p>
<section class="card section-card">@forelse($rivals as $rival)<div class="history-row"><div><strong>{{ $rival['profile']->nickname }}</strong><small>{{ $rival['played'] }} série(s) · {{ $rival['wins'] }} vitórias · {{ $rival['losses'] }} derrotas</small></div><a class="btn btn-secondary" href="{{ route('challenges.index', ['opponent' => $rival['profile']->id]) }}">Revanche</a></div>@empty<p>Suas rivalidades aparecem aqui após a primeira série confirmada.</p>@endforelse</section>
@endsection
