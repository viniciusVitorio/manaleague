@extends('layouts.app')
@section('content')
@php($colorOptions = ['W','U','B','R','G','WU','UB','BR','RG','GW','WB','UR','BG','RW','GU','WUB','UBR','BRG','RGW','GWU','WUBRG','C'])
<header class="hero compact"><div><a class="back-link" href="{{ route('profiles.index') }}">← Meu perfil</a><span class="eyebrow">Configurações</span><h1>Editar perfil</h1><p>Gerencie sua identidade, sua senha e seus decks.</p></div></header>

<div class="profile-columns">
<div>
    <section class="card section-card"><h2>Dados pessoais</h2>
        <form method="POST" action="{{ route('profiles.update') }}">@csrf @method('PATCH')
            <label>Apelido<input name="nickname" value="{{ old('nickname', $profile->nickname) }}" maxlength="80" required></label>
            <label>Avatar <small>Use um emoji</small><input name="avatar" value="{{ old('avatar', $profile->avatar) }}" maxlength="20" placeholder="🧙"></label>
            <button class="btn btn-primary">Salvar perfil</button>
        </form>
    </section>

    <section class="card section-card" id="senha"><h2>Alterar senha</h2>
        @if($errors->password->any())<div class="alert alert-danger"><ul>@foreach($errors->password->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ route('password.change') }}">@csrf @method('PUT')
            <label>Senha atual<input type="password" name="current_password" required autocomplete="current-password"></label>
            <label>Nova senha <small>mín. 8 caracteres, letras e números</small><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
            <label>Confirmar nova senha<input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
            <button class="btn btn-secondary">Alterar senha</button>
            <p class="muted small-note">Você receberá um e-mail de confirmação e as outras sessões serão encerradas.</p>
        </form>
    </section>
</div>

<section class="card section-card"><span class="eyebrow">Seu arsenal</span><h2>Meus decks</h2>
<<<<<<< HEAD
    @forelse($profile->decks as $deck)
        <div class="history-row">
            <div><a href="{{ route('decks.show', $deck) }}"><strong>{{ $deck->is_primary ? '★ ' : '' }}{{ $deck->name }}</strong></a><small>{{ $deck->format ?: 'Formato livre' }} · {{ (int) $deck->cards_sum_quantity }} cartas @if($deck->colors) · @include('partials.color-pips', ['colors' => $deck->colors]) @endif</small></div>
=======
<<<<<<< Updated upstream
@forelse($profile->decks as $deck)<div class="history-row"><div><strong>{{ $deck->is_primary?'★ ':'' }}{{ $deck->name }}</strong><small>{{ $deck->colors?:'Cores não informadas' }}</small></div><div class="deck-actions"><a class="btn btn-small btn-ghost" href="{{ route('decks.edit',$deck) }}">Editar</a>@unless($deck->is_primary)<form method="POST" action="{{ route('decks.primary',$deck) }}">@csrf @method('PATCH')<button class="btn btn-small btn-secondary">Tornar principal</button></form><form method="POST" action="{{ route('decks.destroy',$deck) }}" onsubmit="return confirm('Excluir este deck?')">@csrf @method('DELETE')<button class="icon-button" aria-label="Remover deck">×</button></form>@endunless</div></div>@empty<p class="muted">Nenhum deck cadastrado.</p>@endforelse
<form method="POST" action="{{ route('decks.store') }}">@csrf<label>Nome do deck<input name="name" maxlength="100" required placeholder="Rakdos Vampires"></label><label>Cores<select name="colors"><option value="">Não informar</option>@foreach(['W','U','B','R','G','WU','UB','BR','RG','GW','WB','UR','BG','RW','GU','WUB','UBR','BRG','RGW','GWU','WUBRG','C'] as $color)<option value="{{ $color }}">{{ $color }}</option>@endforeach</select></label><label class="check-label"><input type="checkbox" name="is_primary" value="1"> Usar como principal</label><button class="btn btn-secondary">Adicionar deck</button></form>
</section></div>
=======
    @forelse($profile->decks as $deck)
        <div class="history-row">
            <div><a href="{{ route('decks.show', $deck) }}"><strong>{{ $deck->is_primary ? '★ ' : '' }}{{ $deck->name }}</strong></a><small>{{ $deck->format ?: 'Formato livre' }} · {{ (int) $deck->cards_sum_quantity }} cartas @if($deck->colors) · @include('partials.color-pips', ['colors' => $deck->colors]) @endif · {{ $deck->is_visible_to_friends ? 'Visível para amigos' : '🔒 Privado' }}</small></div>
>>>>>>> f90ce10 (feat - deck configs)
            <div class="deck-actions">
                <a class="btn btn-small btn-secondary" href="{{ route('decks.show', $deck) }}">Cartas</a>
                <a class="btn btn-small btn-ghost" href="{{ route('decks.edit', $deck) }}">Editar</a>
                @unless($deck->is_primary)
                    <form method="POST" action="{{ route('decks.primary', $deck) }}">@csrf @method('PATCH')<button class="btn btn-small btn-ghost" title="Tornar principal">★</button></form>
                    <form method="POST" action="{{ route('decks.destroy', $deck) }}" onsubmit="return confirm('Excluir este deck e todas as cartas?')">@csrf @method('DELETE')<button class="icon-button" aria-label="Remover deck">×</button></form>
                @endunless
            </div>
        </div>
    @empty
        <p class="muted">Nenhum deck cadastrado.</p>
    @endforelse

    <hr class="separator">
    <h3 id="novo-deck">Novo deck</h3>
    <form method="POST" action="{{ route('decks.store') }}">@csrf
        <label>Nome do deck<input name="name" maxlength="100" required placeholder="Rakdos Vampires"></label>
        <label>Formato<select name="format"><option value="">Livre</option>@foreach(\App\Models\Deck::FORMATS as $format)<option value="{{ $format }}">{{ $format }}</option>@endforeach</select></label>
        <label>Cores <small>calculadas automaticamente quando você adiciona cartas</small><select name="colors"><option value="">Definir pelas cartas</option>@foreach($colorOptions as $color)<option value="{{ $color }}">{{ $color }}</option>@endforeach</select></label>
        <label class="check-label"><input type="checkbox" name="is_primary" value="1"> Usar como principal</label>
<<<<<<< HEAD
=======
        <input type="hidden" name="is_visible_to_friends" value="0">
        <label class="check-label"><input type="checkbox" name="is_visible_to_friends" value="1" @checked(old('is_visible_to_friends', '0'))> Mostrar este deck para meus amigos</label>
        <p class="muted small-note">Novos decks começam privados; marque para compartilhar com amigos.</p>
>>>>>>> f90ce10 (feat - deck configs)
        <button class="btn btn-primary">Criar e adicionar cartas</button>
    </form>
</section>
</div>
<<<<<<< HEAD
=======
>>>>>>> Stashed changes
>>>>>>> f90ce10 (feat - deck configs)
@endsection
