@extends('layouts.app')
@section('content')
@include('ranked.partials.head')
@php($tiers = app(\App\Services\Ranked\RankTierResolver::class))
<header><span class="eyebrow">Ranking</span><h1>{{ $season?->name ?? 'Ranking' }}</h1>@if($season)<p class="muted">{{ $season->statusLabel() }} · mínimo de {{ $season->setting('leaderboard_min_games') }} partidas confirmadas para aparecer · desempate: rating, vitórias, partidas jogadas.</p>@endif</header>
@if(! $season)<section class="card section-card"><p>Ainda não há temporadas.</p></section>@else
<form method="GET" class="leaderboard-filters"><label>Temporada<select name="season" data-autosubmit>@foreach($visible as $s)<option value="{{ $s->slug }}" @selected($s->id === $season->id)>{{ $s->name }} ({{ $s->statusLabel() }})</option>@endforeach</select></label>
<label>Formato<select name="format" data-autosubmit>@foreach($formats as $f)<option @selected($f === $format)>{{ $f }}</option>@endforeach</select></label>
@if($clubs->isNotEmpty())<label>Clube<select name="club" data-autosubmit><option value="">Comunidade</option>@foreach($clubs as $c)<option value="{{ $c->id }}" @selected($c->id === $clubId)>{{ $c->name }}</option>@endforeach</select></label>@endif<noscript><button class="btn btn-small">Filtrar</button></noscript></form>
<section class="card section-card"><div class="table-wrap"><table class="standings ranked-table"><thead><tr><th>#</th><th>Jogador</th><th>Patente</th><th>ELO</th><th>V-E-D</th><th>Partidas</th></tr></thead><tbody>
@forelse($paginator as $row)
@if(isset($row['snapshot']))<tr><td>{{ $row['rank'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['snapshot']->tier }}</td><td>{{ $row['snapshot']->final_rating }}</td><td>{{ $row['snapshot']->stats['wins'] ?? 0 }}-{{ $row['snapshot']->stats['draws'] ?? 0 }}-{{ $row['snapshot']->stats['losses'] ?? 0 }}</td><td>{{ $row['snapshot']->stats['played'] ?? 0 }}</td></tr>
@else<tr @class(['is-me' => $row['rating']->profile?->account_user_id === auth()->id()])><td>{{ $row['rank'] }}</td><td>@if($row['rating']->profile?->ranked_public)<a href="{{ route('ranked.players.show', $row['rating']->profile) }}">{{ $row['name'] }}</a>@else{{ $row['name'] }}@endif @if($row['rating']->isProvisional())<span class="provisional">Provisório</span>@endif</td><td>@include('ranked.partials.tier', ['rating' => $row['rating']->rating])</td><td><strong>{{ $row['rating']->rating }}</strong></td><td>{{ $row['rating']->wins }}-{{ $row['rating']->draws }}-{{ $row['rating']->losses }}</td><td>{{ $row['rating']->played }}</td></tr>@endif
@empty<tr><td colspan="6">Ninguém atingiu o mínimo de partidas neste formato ainda.</td></tr>@endforelse
</tbody></table></div>{{ $paginator->links() }}</section>
@endif
@endsection
