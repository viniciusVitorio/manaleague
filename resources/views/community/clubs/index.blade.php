@extends('layouts.app')
@section('content')
@push('head')<link rel="stylesheet" href="{{ \App\Support\VersionedAsset::url('css/hub.css') }}">@endpush
<header class="page-head">
    <div><h1>Clubes</h1><p>A sua loja, o grupo da faculdade, a mesa de sexta: torneios, ranking e avisos num lugar só.</p></div>
    <a class="ui-btn ui-btn-primary" href="#criar"><x-icon name="plus" />Criar clube</a>
</header>

<h2 class="hub-section-title">Meus clubes</h2>
@if($mine->isEmpty())
    <x-ui.empty icon="shield" title="Você ainda não está em nenhum clube." text="Entre num clube público abaixo, abra um link de convite ou crie o seu." />
@else
    <div class="club-grid">
    @foreach($mine as $club)
        <a class="club-card" href="{{ route('clubs.show', $club) }}">
            <span class="club-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($club->name, 0, 2)) }}</span>
            <span class="club-card-body">
                <strong>{{ $club->name }}</strong>
                <small>{{ $club->members_count }} {{ $club->members_count === 1 ? 'membro' : 'membros' }} · {{ $club->tournaments_count }} {{ $club->tournaments_count === 1 ? 'torneio' : 'torneios' }}@if($club->location) · {{ $club->location }}@endif</small>
                @if($n = $next->get($club->id))<small class="club-next"><x-icon name="calendar" size="12" />{{ $n->name }} · {{ $n->tournament_date?->format('d/m') }}</small>@endif
            </span>
            <x-ui.badge :tone="$club->pivot->role === 'member' ? 'neutral' : 'info'">{{ ['owner' => 'Dono', 'organizer' => 'Organizador', 'member' => 'Membro'][$club->pivot->role] }}</x-ui.badge>
        </a>
    @endforeach
    </div>
@endif

<div class="hub-grid">
<div class="hub-main">
    <x-ui.card title="Descobrir clubes públicos" icon="globe">
        <form method="GET" class="hub-filters" role="search"><label class="hub-search"><x-icon name="search" /><input type="search" name="q" value="{{ $q }}" placeholder="Nome ou cidade" aria-label="Buscar clubes"></label><button class="ui-btn ui-btn-outline">Buscar</button></form>
        @forelse($discover as $club)
            <div class="ui-row">
                <span class="club-mark is-sm" aria-hidden="true">{{ mb_strtoupper(mb_substr($club->name, 0, 2)) }}</span>
                <a class="ui-row-main" href="{{ route('clubs.show', $club) }}"><span class="ui-row-title">{{ $club->name }}</span><span class="ui-row-sub">{{ $club->members_count }} membros · {{ $club->tournaments_count }} torneios @if($club->location) · {{ $club->location }}@endif</span></a>
                <form method="POST" action="{{ route('clubs.join', $club) }}">@csrf<button class="ui-btn ui-btn-outline ui-btn-sm">Entrar</button></form>
            </div>
        @empty
            <p class="muted small">{{ $q !== '' ? 'Nenhum clube público com esse nome.' : 'Nenhum clube público além dos seus.' }}</p>
        @endforelse
        {{ $discover->links() }}
    </x-ui.card>
</div>
<aside class="hub-side">
    <x-ui.card title="Criar clube" icon="plus" id="criar">
        <form method="POST" action="{{ route('clubs.store') }}" class="stack-form">@csrf
            <label>Nome<input name="name" maxlength="120" value="{{ old('name') }}" required placeholder="Ex.: Liga Pauper da Loja X"></label>
            <label>Cidade / local <span class="muted">(opcional)</span><input name="location" maxlength="120" value="{{ old('location') }}" placeholder="Ex.: Porto Alegre · Loja X"></label>
            <label>Descrição<textarea name="description" maxlength="2000" rows="3">{{ old('description') }}</textarea></label>
            <label>Quem pode entrar<select name="privacy"><option value="private">Privado: só com convite</option><option value="public">Público: qualquer um entra</option></select></label>
            <button class="ui-btn ui-btn-primary">Criar clube</button>
        </form>
    </x-ui.card>
</aside>
</div>
@endsection
