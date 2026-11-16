@extends('layouts.app')
@section('content')
@push('head')<script src="{{ \App\Support\VersionedAsset::url('js/emoji-input.js') }}" defer></script>@endpush
@php($me = auth()->user())
<header class="hero compact"><div><a class="back-link" href="{{ route('profiles.index') }}">← Meu perfil</a><span class="eyebrow">Configurações</span><h1>Editar perfil</h1><p>Gerencie sua identidade, sua senha e suas preferências.</p></div></header>

{{-- Configurações no padrão "menu de seções + uma coluna": cada cartão tem título, explicação, campos e um rodapé com a ação. --}}
<div class="settings-layout">
<nav class="settings-nav" aria-label="Seções das configurações">
    <a href="#foto"><x-icon name="image" />Foto de perfil</a>
    <a href="#dados"><x-icon name="pencil" />Dados pessoais</a>
    <a href="#endereco"><x-icon name="link" />Nome de usuário</a>
    <a href="#preferencias"><x-icon name="settings" />Preferências</a>
    <a href="#senha"><x-icon name="lock" />Senha</a>
</nav>

<div class="settings-main">
    <section class="card settings-card" id="foto">
        <div class="settings-card-body">
            <h2>Foto de perfil</h2>
            <p class="settings-card-desc">Aparece no seu perfil, nas partidas e nos torneios.</p>
            @error('photo')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror
            <div class="avatar-upload">
                <x-avatar :of="$profile" class="profile-avatar large" />
                <form class="avatar-upload-actions" method="POST" action="{{ route('profiles.photo.update') }}" enctype="multipart/form-data">@csrf
                    <label for="photo">Escolher foto <small>JPG, PNG ou WebP até {{ intdiv((int) config('avatars.max_kilobytes'), 1024) }} MB. Recortamos no centro em {{ config('avatars.size') }}×{{ config('avatars.size') }} e removemos os metadados (como localização).</small></label>
                    <div class="settings-inline">
                        <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" required>
                        <button class="ui-btn ui-btn-outline"><x-icon name="upload" />Enviar foto</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="settings-card-foot">
            <p class="muted small-note">Sem foto, usamos o emoji dos dados pessoais (ou a inicial do apelido).</p>
            @if($profile->avatar_path)
                <form method="POST" action="{{ route('profiles.photo.destroy') }}" data-confirm="Remover a foto de perfil?">@csrf @method('DELETE')<button class="ui-btn ui-btn-ghost ui-btn-sm"><x-icon name="trash" />Remover foto</button></form>
            @endif
        </div>
    </section>

    <section class="card settings-card" id="dados">
        <form method="POST" action="{{ route('profiles.update') }}">@csrf @method('PATCH')
            <div class="settings-card-body">
                <h2>Dados pessoais</h2>
                <p class="settings-card-desc">Como os outros jogadores veem você.</p>
                <div class="settings-fields settings-fields-emoji">
                    <label>Nome de exibição <small>Aparece no perfil, nos torneios e no ranking. Pode ter espaços e acentos.</small><input name="nickname" value="{{ old('nickname', $profile->nickname) }}" maxlength="80" required></label>
                    <label>Avatar <small>Só 1 emoji. Digitar outro troca o atual.</small><input class="emoji-input" name="avatar" value="{{ old('avatar', $profile->emoji()) }}" maxlength="20" placeholder="🧙" autocomplete="off" spellcheck="false" data-single-emoji></label>
                </div>
            </div>
            <div class="settings-card-foot">
                <p class="muted small-note">O nome de exibição não muda o link do seu perfil.</p>
                <button class="ui-btn ui-btn-primary">Salvar perfil</button>
            </div>
        </form>
    </section>

    <section class="card settings-card" id="endereco">
        @php($nextHandleChange = $profile->nextHandleChangeAt())
        <form method="POST" action="{{ route('profiles.handle.update') }}">@csrf @method('PATCH')
            <div class="settings-card-body">
                <h2>Nome de usuário</h2>
                <p class="settings-card-desc">É o link do seu perfil. 3 a 30 caracteres: letras minúsculas, números e hífen.</p>
                <label for="handle" class="sr-only">Usuário</label>
                <div class="handle-input">
                    <span aria-hidden="true">{{ request()->getHost() }}/p/</span>
                    <input id="handle" name="handle" value="{{ old('handle', $profile->handle) }}" minlength="3" maxlength="30" pattern="[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?" autocomplete="off" autocapitalize="none" spellcheck="false" required @disabled($nextHandleChange) aria-describedby="handle-help">
                </div>
            </div>
            <div class="settings-card-foot">
                <p class="muted small-note" id="handle-help">
                    @if($nextHandleChange)
                        Você trocou o nome de usuário recentemente. Poderá trocar de novo em {{ $nextHandleChange->format('d/m/Y') }}.
                    @else
                        Você pode trocar o usuário uma vez a cada {{ \App\Models\PlayerProfile::HANDLE_COOLDOWN_DAYS }} dias; o link antigo continua levando ao seu perfil por {{ \App\Models\PlayerProfile::HANDLE_HOLD_DAYS }} dias.
                    @endif
                </p>
                <button class="ui-btn ui-btn-outline" @disabled($nextHandleChange)>Salvar usuário</button>
            </div>
        </form>
    </section>

    <section class="card settings-card" id="preferencias">
        <form method="POST" action="{{ route('preferences.update') }}">@csrf @method('PATCH')
            <div class="settings-card-body">
                <h2>Preferências</h2>
                <p class="settings-card-desc">Aparência do site e do contador de vida.</p>
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
                <fieldset class="pref-group">
                    <legend>Cor no contador de vida</legend>
                    <p class="muted small-note">O fundo do seu lado da mesa no contador. Se o adversário escolher a mesma cor, um dos dois fica com uma cor automática.</p>
                    <div class="life-color-options">
                        <label class="life-color-option" title="Automática"><input type="radio" name="life_color" value="" @checked(! \App\Support\LifeColor::of($me))><span class="life-color-dot is-auto" aria-hidden="true">A</span><span class="sr-only">Automática</span></label>
                        @foreach(\App\Support\LifeColor::ALL as $hex => $colorName)
                            <label class="life-color-option" title="{{ $colorName }}"><input type="radio" name="life_color" value="{{ $hex }}" @checked(\App\Support\LifeColor::of($me) === $hex)><span class="life-color-dot" style="--c: {{ $hex }}" aria-hidden="true"></span><span class="sr-only">{{ $colorName }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
                <fieldset class="pref-group">
                    <legend>Decks</legend>
                    <input type="hidden" name="show_deck_ownership" value="0">
                    <label class="check-label"><input type="checkbox" name="show_deck_ownership" value="1" @checked($me->show_deck_ownership !== false)> Mostrar nos meus decks quantas cópias tenho na coleção</label>
                    <p class="muted small-note">O contador “2/4” ao lado de cada carta. Só aparece se você tiver cartas cadastradas na coleção.</p>
                </fieldset>
            </div>
            <div class="settings-card-foot">
                <p class="muted small-note">O tema também troca pelo botão de sol e lua no topo.</p>
                <button class="ui-btn ui-btn-outline">Salvar preferências</button>
            </div>
        </form>
    </section>

    <section class="card settings-card" id="senha">
        <form method="POST" action="{{ route('password.change') }}">@csrf @method('PUT')
            <div class="settings-card-body">
                <h2>Alterar senha</h2>
                <p class="settings-card-desc">Mínimo de 8 caracteres, com letras e números.</p>
                @if($errors->password->any())<div class="alert alert-danger"><ul>@foreach($errors->password->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <label>Senha atual<input type="password" name="current_password" required autocomplete="current-password"></label>
                <div class="settings-fields">
                    <label>Nova senha<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
                    <label>Confirmar nova senha<input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
                </div>
            </div>
            <div class="settings-card-foot">
                <p class="muted small-note">Você receberá um e-mail de confirmação e as outras sessões serão encerradas.</p>
                <button class="ui-btn ui-btn-outline">Alterar senha</button>
            </div>
        </form>
    </section>
</div>
</div>
@endsection
