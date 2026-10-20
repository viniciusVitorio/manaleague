@extends('layouts.app')
@section('content')
@include('community.nav')
<h1>Atividades</h1><p>Somente atividades às quais você tem acesso agora.</p>
<section class="community-card">@forelse($activities as $activity)<article><h2><a href="{{ app(\App\Services\Community\CommunityAccess::class)->link(auth()->user(),$activity->subject_type,$activity->subject_id) }}">{{ $activity->title }}</a></h2><time datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->format('d/m/Y H:i') }}</time></article>@empty<p>Nenhuma atividade disponível.</p>@endforelse
{{ $activities->links() }}</section>
@endsection
