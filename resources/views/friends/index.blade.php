@extends('layouts.app')
@section('content')
<header class="hero compact"><div><span class="eyebrow">Sua mesa</span><h1>Amigos</h1><p class="muted">Encontre jogadores pelo apelido ou pelo código de amigo e monte seu playgroup.</p></div></header>

<div class="friends-layout">
<div>
    <section class="card section-card">
        <span class="eyebrow">Adicionar amigo</span><h2>Buscar jogadores</h2>
        <form method="GET" action="{{ route('friends.index') }}" class="search-row">
            <input type="search" name="q" value="{{ $term }}" placeholder="Apelido (mín. 3 letras) ou código, ex.: 7KQ2-M9XA" maxlength="80" autocomplete="off" aria-label="Buscar jogadores">
            <button class="btn btn-primary">Buscar</button>
        </form>
        @if($term !== '')
            <div class="people-list">
            @forelse($results as $person)
                @php($state = $statusById[$person->id] ?? null)
                <div class="person-row">
                    <span class="profile-avatar">{{ $person->avatar ?: mb_strtoupper(mb_substr($person->nickname, 0, 1)) }}</span>
                    <div class="person-info"><strong>{{ $person->nickname }}</strong><small>{{ $person->preferred_deck_name ?: 'Deck principal não informado' }}</small></div>
                    @if($state === 'friend')<span class="pill pill-ok">Amigos</span>
                    @elseif($state === 'sent')<span class="pill">Pedido enviado</span>
                    @elseif($state === 'incoming')<form method="POST" action="{{ route('friends.accept-code', $person->friend_code) }}">@csrf @method('PATCH')<button class="btn btn-small btn-primary">Aceitar pedido</button></form>
                    @else<form method="POST" action="{{ route('friends.store', $person->friend_code) }}">@csrf<button class="btn btn-small btn-secondary">Adicionar</button></form>@endif
                </div>
            @empty
                <p class="muted">Ninguém encontrado. Perfis privados só aparecem pelo código de amigo exato.</p>
            @endforelse
            </div>
        @endif
    </section>

    @if($incoming->isNotEmpty())
    <section class="card section-card highlight-card">
        <span class="eyebrow">Aguardando você</span><h2>Pedidos recebidos <span class="count">{{ $incoming->count() }}</span></h2>
        <div class="people-list">
        @foreach($incoming as $person)
            <div class="person-row">
                <span class="profile-avatar">{{ $person->avatar ?: mb_strtoupper(mb_substr($person->nickname, 0, 1)) }}</span>
                <div class="person-info"><strong>{{ $person->nickname }}</strong><small>quer ser seu amigo</small></div>
                <div class="deck-actions">
                    <form method="POST" action="{{ route('friends.accept-code', $person->friend_code) }}">@csrf @method('PATCH')<button class="btn btn-small btn-primary">Aceitar</button></form>
                    <form method="POST" action="{{ route('friends.destroy', $person->friend_code) }}">@csrf @method('DELETE')<button class="btn btn-small btn-ghost">Recusar</button></form>
                </div>
            </div>
        @endforeach
        </div>
    </section>
    @endif

    @if($suggestions->isNotEmpty())
    <section class="card section-card">
        <span class="eyebrow">Amigos de amigos</span><h2>Talvez você conheça</h2>
        <div class="people-list">
        @foreach($suggestions as $s)
            <div class="person-row">
                <span class="profile-avatar">{{ $s['profile']->avatar ?: mb_strtoupper(mb_substr($s['profile']->nickname, 0, 1)) }}</span>
                <div class="person-info"><a href="{{ route('profiles.show', $s['profile']) }}"><strong>{{ $s['profile']->nickname }}</strong></a><small>{{ $s['mutual']->count() === 1 ? 'Amigo de '.$s['mutual'][0]->nickname : $s['mutual']->count().' amigos em comum: '.$s['mutual']->take(2)->pluck('nickname')->join(', ').($s['mutual']->count() > 2 ? '…' : '') }}</small></div>
                <form method="POST" action="{{ route('friends.store', $s['profile']->friend_code) }}">@csrf<button class="btn btn-small btn-secondary">Adicionar</button></form>
            </div>
        @endforeach
        </div>
    </section>
    @endif

    <section class="card section-card">
        <span class="eyebrow">Playgroup</span><h2>Meus amigos <span class="count">{{ $friends->count() }}</span></h2>
        <div class="people-list">
        @forelse($friends as $friend)
            <div class="person-row">
                <span class="profile-avatar">{{ $friend->avatar ?: mb_strtoupper(mb_substr($friend->nickname, 0, 1)) }}</span>
                <div class="person-info"><a href="{{ route('profiles.show', $friend) }}"><strong>{{ $friend->nickname }}</strong></a><small>{{ $friend->preferred_deck_name ?: 'Deck principal não informado' }}@if($friend->preferred_deck_colors) · @include('partials.color-pips', ['colors' => $friend->preferred_deck_colors])@endif</small></div>
                <div class="deck-actions">
                    <a class="btn btn-small btn-ghost" href="{{ route('profiles.show', $friend) }}">Ver perfil</a>
                    <form method="POST" action="{{ route('friends.destroy', $friend->friend_code) }}" onsubmit="return confirm('Desfazer esta amizade?')">@csrf @method('DELETE')<button class="icon-button" aria-label="Desfazer amizade">×</button></form>
                </div>
            </div>
        @empty
            <div class="empty-inline"><p>Você ainda não tem amigos por aqui.</p><p class="muted">Busque pelo apelido acima ou mande seu código para a galera.</p></div>
        @endforelse
        </div>
    </section>
</div>

<aside>
    <section class="card section-card code-card">
        <span class="eyebrow">Seu código de amigo</span>
        <div class="friend-code" id="friend-code">{{ $profile->formattedFriendCode() }}</div>
        <p class="muted">Quem tiver este código consegue te enviar pedido, mesmo com o perfil privado.</p>
        <button type="button" class="btn btn-secondary btn-block" onclick="navigator.clipboard.writeText(document.getElementById('friend-code').textContent.trim());this.textContent='Código copiado'">Copiar código</button>
    </section>

    <section class="card section-card">
        <span class="eyebrow">Privacidade</span><h2>Quem pode me encontrar</h2>
        <form method="POST" action="{{ route('friends.privacy') }}">@csrf @method('PATCH')
            <label class="radio-label"><input type="radio" name="discoverable" value="1" @checked($profile->discoverable)> <span><strong>Todo mundo</strong><small>Apareço na busca por apelido.</small></span></label>
            <label class="radio-label"><input type="radio" name="discoverable" value="0" @checked(! $profile->discoverable)> <span><strong>Só com código</strong><small>Só me acha quem tiver meu código.</small></span></label>
            <button class="btn btn-ghost btn-block">Salvar privacidade</button>
        </form>
    </section>

    @if($outgoing->isNotEmpty())
    <section class="card section-card">
        <span class="eyebrow">Enviados</span><h2>Aguardando resposta</h2>
        <div class="people-list compact">
        @foreach($outgoing as $person)
            <div class="person-row">
                <div class="person-info"><strong>{{ $person->avatar }} {{ $person->nickname }}</strong></div>
                <form method="POST" action="{{ route('friends.destroy', $person->friend_code) }}">@csrf @method('DELETE')<button class="btn btn-small btn-ghost">Cancelar</button></form>
            </div>
        @endforeach
        </div>
    </section>
    @endif
</aside>
</div>
@endsection
