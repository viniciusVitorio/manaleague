@extends('layouts.app')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/live.css') }}">@endpush
@section('content')
<h1>Resultados enviados · {{ $tournament->name }}</h1>
@forelse($submissions as $submission)
<article class="card section-card mobile-result"><h2><x-profile-link :of="$submission->gameMatch->playerOne">{{ $submission->gameMatch->playerOne?->displayName() }}</x-profile-link> × <x-profile-link :of="$submission->gameMatch->playerTwo">{{ $submission->gameMatch->playerTwo?->displayName() }}</x-profile-link></h2>
<p>{{ $submission->status }} · {{ $submission->player_one_games }} × {{ $submission->player_two_games }}</p>
@if($submission->dispute_reason)<p>Contestação: {{ $submission->dispute_reason }}</p>@endif
@if($submission->resolution_reason)<p>Resolução: {{ $submission->resolution_reason }}</p>@endif
@if(in_array($submission->status,['pending','disputed']))
<form method="POST" action="{{ route('matches.submissions.resolve',$submission) }}">@csrf
<label>{{ $submission->gameMatch->playerOne?->displayName() }}<input type="number" inputmode="numeric" name="player_one_games" min="0" max="3" value="{{ $submission->player_one_games }}" required></label>
<label>{{ $submission->gameMatch->playerTwo?->displayName() }}<input type="number" inputmode="numeric" name="player_two_games" min="0" max="3" value="{{ $submission->player_two_games }}" required></label>
<label>Motivo da decisão<textarea name="reason" minlength="3" maxlength="1000" required></textarea></label><button class="btn btn-primary">Resolver e publicar</button></form>
@endif
<details class="submission-audit"><summary>Auditoria</summary><ol>@foreach($events->get($submission->id,collect()) as $event)<li>{{ $event->created_at }} · {{ $event->action }} · Conta #{{ $event->actor_id }}<pre>{{ json_encode(json_decode($event->data),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></li>@endforeach</ol></details>
</article>
@empty<p>Nenhum resultado enviado.</p>@endforelse
{{ $submissions->links() }}
@endsection
