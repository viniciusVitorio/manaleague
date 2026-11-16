@extends('layouts.app')
@section('content')
<header class="hero compact"><div><a class="back-link" href="{{ route('decks.show', $deck) }}">← Voltar ao deck</a><span class="eyebrow">Arsenal</span><h1>Editar deck</h1><p>Nome, formato e quem pode ver <b>{{ $deck->name }}</b>. As cartas você edita na página do deck.</p></div></header>

{{-- Mesmo padrão de Editar perfil: menu de seções + uma coluna de cartões. Os dois primeiros cartões são um formulário só, com a ação no fim. --}}
<div class="settings-layout">
<nav class="settings-nav" aria-label="Seções do deck">
    <a href="#dados"><x-icon name="pencil" />Dados do deck</a>
    <a href="#visibilidade"><x-icon name="eye" />Visibilidade</a>
    <a href="#excluir"><x-icon name="trash" />Excluir deck</a>
</nav>

<div class="settings-main">
    <form class="settings-form" method="POST" action="{{ route('decks.update', $deck) }}">@csrf @method('PATCH')
        <section class="card settings-card" id="dados">
            <div class="settings-card-body">
                <h2>Dados do deck</h2>
                <p class="settings-card-desc">Como o deck aparece nas suas listas, no perfil e nas partidas.</p>
                <label>Nome<input name="name" value="{{ old('name', $deck->name) }}" maxlength="100" required></label>
                <div class="settings-fields">
                    <label>Formato<select name="format"><option value="">Livre</option>@foreach(\App\Models\Deck::FORMATS as $format)<option value="{{ $format }}" @selected(old('format', $deck->format) === $format)>{{ $format }}</option>@endforeach</select></label>
                    <label>Cores<select name="colors"><option value="">Não informar</option>@foreach(['W','U','B','R','G','WU','UB','BR','RG','GW','WB','UR','BG','RW','GU','WUB','UBR','BRG','RGW','GWU','WUBRG','C'] as $color)<option value="{{ $color }}" @selected(old('colors', $deck->colors) === $color)>{{ $color }}</option>@endforeach</select></label>
                </div>
            </div>
            <div class="settings-card-foot">
                <p class="muted small-note">Se o deck tiver cartas, as cores vêm da identidade delas e a escolha acima é ignorada.</p>
            </div>
        </section>

        <section class="card settings-card" id="visibilidade">
            <div class="settings-card-body">
                <h2>Visibilidade</h2>
                <p class="settings-card-desc">Onde este deck aparece e quem pode ver a lista.</p>
                <fieldset class="pref-group">
                    <legend>Perfil</legend>
                    <label class="check-label"><input type="checkbox" name="is_primary" value="1" @checked(old('is_primary', $deck->is_primary))> Deck principal</label>
                    <p class="muted small-note">É o deck que aparece como o seu no perfil. Marcar aqui tira a marca do deck principal anterior.</p>
                </fieldset>
                <fieldset class="pref-group">
                    <legend>Amigos</legend>
                    <input type="hidden" name="is_visible_to_friends" value="0">
                    <label class="check-label"><input type="checkbox" name="is_visible_to_friends" value="1" @checked(old('is_visible_to_friends', $deck->is_visible_to_friends))> Mostrar este deck para meus amigos</label>
                    <p class="muted small-note">Se desmarcar, o deck e sua lista de cartas ficam ocultos no perfil e pelo link direto.</p>
                </fieldset>
            </div>
        </section>

        <div class="settings-actions">
            <a class="ui-btn ui-btn-ghost" href="{{ route('decks.show', $deck) }}">Cancelar</a>
            <button class="ui-btn ui-btn-primary">Salvar alterações</button>
        </div>
    </form>

    <section class="card settings-card" id="excluir">
        <div class="settings-card-body">
            <h2>Excluir deck</h2>
            <p class="settings-card-desc">Apaga o deck e todas as cartas dele. Não dá para desfazer.</p>
        </div>
        <div class="settings-card-foot">
            <p class="muted small-note">{{ $deck->is_primary ? 'Este é o seu deck principal: escolha outro deck principal antes de excluir este.' : 'A sua coleção não é alterada.' }}</p>
            <form method="POST" action="{{ route('decks.destroy', $deck) }}" data-confirm="Excluir este deck e todas as cartas?">@csrf @method('DELETE')<button class="ui-btn ui-btn-outline settings-danger" @disabled($deck->is_primary)><x-icon name="trash" />Excluir deck</button></form>
        </div>
    </section>
</div>
</div>
@endsection
