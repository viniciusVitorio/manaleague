@extends('layouts.app')
@section('content')
<a class="back-link" href="{{ route('challenges.index') }}">← Desafios</a>
<header class="page-head">
    <div><h1>Novo desafio</h1><p>Escolha o amigo e como vai ser a série. Ele recebe o convite no sino e aceita ou recusa.</p></div>
</header>

@if($friends->isEmpty())
    <x-ui.card>
        <x-ui.empty icon="user-plus" title="Você ainda não tem amigos com conta." text="Desafios são entre amigos. Adicione alguém para mandar o primeiro convite." :href="route('friends.index')" action="Encontrar amigos" />
    </x-ui.card>
@else
    @php($picked = (int) old('opponent_id', request('opponent', $friends->count() === 1 ? $friends->first()->id : 0)))
    <form method="POST" action="{{ route('challenges.store') }}" class="challenge-form">@csrf
        <x-ui.card title="Adversário" icon="users">
            <div class="friend-choices" role="radiogroup" aria-label="Adversário">
                @foreach($friends as $friend)
                    <label class="friend-choice">
                        <input type="radio" name="opponent_id" value="{{ $friend->id }}" @checked($picked === $friend->id) required>
                        <x-avatar :of="$friend" class="ui-avatar" aria-hidden="true" />
                        <span>{{ $friend->nickname }}</span>
                    </label>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card title="Série" icon="swords">
            <div class="challenge-fields">
                <label>Formato
                    <input name="format" maxlength="60" value="{{ old('format', 'Pauper') }}" list="challenge-formats" required>
                    <datalist id="challenge-formats">@foreach(array_unique(array_merge(config('ranked.formats'), ['Commander', 'Premodern', 'Cube'])) as $format)<option value="{{ $format }}">@endforeach</datalist>
                </label>
                <fieldset class="choice-group"><legend>Melhor de</legend>
                    <div class="choice-chips">
                        @foreach([1, 3, 5] as $n)
                            <label class="choice-chip"><input type="radio" name="best_of" value="{{ $n }}" @checked((int) old('best_of', 3) === $n)><span>{{ $n }} {{ $n === 1 ? 'game' : 'games' }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
                <fieldset class="choice-group"><legend>O convite vale por</legend>
                    <div class="choice-chips">
                        @foreach([24 => '24 horas', 48 => '48 horas', 168 => '7 dias'] as $hours => $label)
                            <label class="choice-chip"><input type="radio" name="expires_in" value="{{ $hours }}" @checked((int) old('expires_in', 24) === $hours)><span>{{ $label }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
            </div>
            <label class="ui-option ui-option-box"><span class="ui-option-text"><span class="ui-option-title">Partida ranqueada</span><span class="ui-option-desc">Vale ELO/PDL nos formatos {{ implode(', ', config('ranked.formats')) }}. Máximo de {{ config('ranked.defaults.pair_limit_24h') }} ranqueadas com o mesmo amigo a cada 24h.</span></span><input type="checkbox" class="ui-switch" role="switch" name="ranked" value="1" @checked(old('ranked', request('ranked')))></label>
        </x-ui.card>

        <div class="challenge-form-actions">
            <a class="ui-btn ui-btn-ghost" href="{{ route('challenges.index') }}">Cancelar</a>
            <button class="ui-btn ui-btn-primary"><x-icon name="swords" />Enviar desafio</button>
        </div>
    </form>
@endif
@endsection
