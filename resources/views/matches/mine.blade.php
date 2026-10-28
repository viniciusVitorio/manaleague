@extends('layouts.app')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/live.css') }}">@endpush
@section('content')
<h1>Minhas partidas</h1>
@forelse($matches as $match)
<article class="card section-card mobile-result"><h2>{{ $match->tournament->name }} · Rodada {{ $match->round }}</h2><p>@if($match->pod_player_ids)@foreach($match->tournament->players->whereIn('id', $match->pod_player_ids)->values() as $podPlayer)@unless($loop->first) · @endunless<x-profile-link :of="$podPlayer">{{ $podPlayer->displayName() }}</x-profile-link>@endforeach @else<x-profile-link :of="$match->playerOne">{{ $match->playerOne?->displayName() }}</x-profile-link> × <x-profile-link :of="$match->playerTwo">{{ $match->playerTwo?->displayName() ?? 'BYE' }}</x-profile-link>@endif</p>
@php($submission=$submissions->get($match->id))
@if($submission)
<p>{{ match($submission->status) {'pending'=>'Aguardando confirmação','disputed'=>'Contestado · aguardando organizador','confirmed'=>'Confirmado','resolved'=>'Resolvido pelo organizador',default=>$submission->status} }} · {{ $submission->player_one_games }} × {{ $submission->player_two_games }}</p>
@if($submission->dispute_reason)<p>Contestação: {{ $submission->dispute_reason }}</p>@endif
@if($submission->resolution_reason)<p>Resolução: {{ $submission->resolution_reason }}</p>@endif
@if($submission->status==='pending' && $submission->submitted_by!==auth()->id())
<form method="POST" action="{{ route('matches.submissions.respond',$submission) }}">@csrf<input type="hidden" name="action" value="confirm"><button class="btn btn-primary">Confirmar placar</button></form>
<form method="POST" action="{{ route('matches.submissions.respond',$submission) }}">@csrf<input type="hidden" name="action" value="dispute"><label>Motivo da contestação<textarea name="reason" minlength="3" maxlength="1000" required></textarea></label><button class="btn btn-secondary">Contestar</button></form>
@endif
@elseif($match->status==='pending' && $match->player_two_id && !$match->pod_player_ids)
<p class="life-cta"><a class="btn btn-primary" href="{{ route('life.tournament',[$match->tournament,$match]) }}">❤ Abrir contador de vida</a> <small>Quando alguém zerar, o placar já vem pronto pra enviar.</small></p>
<form method="POST" action="{{ route('matches.submissions.store',[$match->tournament,$match]) }}">@csrf
<input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
<label>{{ $match->playerOne?->displayName() }} <input type="number" name="player_one_games" min="0" max="3" required></label>
<label>{{ $match->playerTwo?->displayName() }} <input type="number" name="player_two_games" min="0" max="3" required></label>
<button class="btn btn-primary">Enviar resultado</button></form>
@elseif($match->pod_player_ids)@php($mySeat = $match->tournament->players->first(fn($p) => in_array($p->id, $match->pod_player_ids, true) && $p->profile?->account_user_id === auth()->id()))
<p>Mesa Commander · @if($match->status === 'finished' && $mySeat && isset(($match->pod_scores ?? [])[$mySeat->id]))você ficou em {{ $match->pod_scores[$mySeat->id] }}º @else o organizador lança as posições ao fim da mesa @endif</p>
@if($match->status === 'pending')<p class="life-cta"><a class="btn btn-secondary" href="{{ route('life.tournament',[$match->tournament,$match]) }}">❤ Contador de vida da mesa</a></p>@endif
@else<p>{{ $match->player_one_games ?? '–' }} × {{ $match->player_two_games ?? '–' }} · {{ ['finished'=>'Encerrada','bye'=>'Folga (vitória automática)','pending'=>'Aguardando'][$match->status] ?? $match->status }}</p>@endif
</article>
@empty<p>Nenhuma partida vinculada à sua conta.</p>@endforelse
{{ $matches->links() }}
@endsection
