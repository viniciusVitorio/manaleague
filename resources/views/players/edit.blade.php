@extends('layouts.app')
@section('content')
@php($me = auth()->user())
<header class="hero compact"><div><a class="back-link" href="{{ route('profiles.index') }}">← Meu perfil</a><span class="eyebrow">Configurações</span><h1>Editar perfil</h1><p>Gerencie sua identidade, sua senha e suas preferências.</p></div></header>

<div class="profile-columns">
<div>
    <section class="card section-card" id="foto"><h2>Foto de perfil</h2>
        @error('photo')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
        <div class="avatar-upload">
            <x-avatar :of="$profile" class="profile-avatar large" />
            <div class="avatar-upload-actions">
                <form method="POST" action="{{ route('profiles.photo.update') }}" enctype="multipart/form-data">@csrf
                    <label>Escolher foto <small>JPG, PNG ou WebP até {{ intdiv((int) config('avatars.max_kilobytes'), 1024) }} MB. Recortamos no centro em {{ config('avatars.size') }}×{{ config('avatars.size') }} e removemos os metadados (como localização).</small>
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label>
                    <button class="btn btn-primary">Enviar foto</button>
                </form>
                @if($profile->avatar_path)
                    <form method="POST" action="{{ route('profiles.photo.destroy') }}">@csrf @method('DELETE')<button class="btn btn-ghost">Remover foto</button></form>
                @endif
            </div>
        </div>
        <p class="muted small-note">Sem foto, usamos o emoji abaixo (ou a inicial do apelido).</p>
    </section>

    <section class="card section-card"><h2>Dados pessoais</h2>
        <form method="POST" action="{{ route('profiles.update') }}">@csrf @method('PATCH')
            <label>Nome de exibição <small>Aparece no perfil, nos torneios e no ranking. Pode ter espaços e acentos.</small><input name="nickname" value="{{ old('nickname', $profile->nickname) }}" maxlength="80" required></label>
            <label>Avatar <small>Use um emoji</small><input name="avatar" value="{{ old('avatar', $profile->avatar) }}" maxlength="20" placeholder="🧙"></label>
            <button class="btn btn-primary">Salvar perfil</button>
        </form>
    </section>

    <section class="card section-card" id="endereco"><h2>Nome de usuário</h2>
        @php($nextHandleChange = $profile->nextHandleChangeAt())
        <form method="POST" action="{{ route('profiles.handle.update') }}">@csrf @method('PATCH')
            <label for="handle">Usuário <small>É o link do seu perfil. 3 a 30 caracteres: letras minúsculas, números e hífen.</small></label>
            <div class="handle-input">
                <span aria-hidden="true">{{ request()->getHost() }}/p/</span>
                <input id="handle" name="handle" value="{{ old('handle', $profile->handle) }}" minlength="3" maxlength="30" pattern="[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?" autocomplete="off" autocapitalize="none" spellcheck="false" required @disabled($nextHandleChange) aria-describedby="handle-help">
            </div>
            <button class="btn btn-secondary" @disabled($nextHandleChange)>Salvar usuário</button>
            <p class="muted small-note" id="handle-help">
                @if($nextHandleChange)
                    Você trocou o nome de usuário recentemente. Poderá trocar de novo em {{ $nextHandleChange->format('d/m/Y') }}.
                @else
                    Trocar o nome de exibição não muda o usuário. Você pode trocar o usuário uma vez a cada {{ \App\Models\PlayerProfile::HANDLE_COOLDOWN_DAYS }} dias; o link antigo continua levando ao seu perfil por {{ \App\Models\PlayerProfile::HANDLE_HOLD_DAYS }} dias.
                @endif
            </p>
        </form>
    </section>

    <section class="card section-card" id="preferencias"><h2>Preferências</h2>
        <form method="POST" action="{{ route('preferences.update') }}">@csrf @method('PATCH')
            <fieldset class="pref-group">
                <legend>Tema</legend>
                <div class="theme-options">
                    @foreach(['dark' => 'Escuro', 'light' => 'Claro'] as $value => $label)
                        <label class="theme-option theme-option-{{ $value }}">
                            <input type="radio" name="theme" value="{{ $value }}" @checked(\App\Support\Theme::current() === $value)>
                            <span class="theme-swatch" aria-hidden="true"><span></span><span></span></span>
                            <span>{{ $label }}{{ $value === 'dark' ? ' (padrão)' : '' }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <input type="hidden" name="show_deck_ownership" value="0">
            <label class="check-label"><input type="checkbox" name="show_deck_ownership" value="1" @checked($me->show_deck_ownership !== false)> Mostrar nos meus decks quantas cópias tenho na coleção</label>
            <p class="muted small-note">O contador “2/4” ao lado de cada carta. Só aparece se você tiver cartas cadastradas na coleção.</p>
            <button class="btn btn-secondary">Salvar preferências</button>
        </form>
    </section>
</div>

<div>
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
</div>
@endsection
