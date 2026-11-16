@extends('layouts.app')
@section('content')
@include('decks.tools-nav')
@php($mineId = auth()->user()->playerProfile?->id)
@php($boardNames = ['side' => 'Sideboard', 'commander' => 'Comandante'])
<header class="page-head">
    <div><h1>Comparar decks</h1><p>Veja o que muda de um deck para o outro. Entram os seus decks e os que amigos compartilharam com você.</p></div>
</header>

<form class="card deckcmp-form" method="GET" action="{{ route('decks.compare') }}">
    @foreach(['left' => ['De', $left], 'right' => ['Para', $right]] as $side => [$sideLabel, $sideDeck])
        <label>{{ $sideLabel }}
            <select name="{{ $side }}" required>
                <option value="">Escolha um deck</option>
                <optgroup label="Meus decks">
                    @foreach($decks->where('player_profile_id', $mineId) as $deck)<option value="{{ $deck->id }}" @selected($sideDeck?->id === $deck->id)>{{ $deck->name }}</option>@endforeach
                </optgroup>
                @if($decks->where('player_profile_id', '!=', $mineId)->isNotEmpty())
                    <optgroup label="Decks de amigos">
                        @foreach($decks->where('player_profile_id', '!=', $mineId) as $deck)<option value="{{ $deck->id }}" @selected($sideDeck?->id === $deck->id)>{{ $deck->name }} · {{ $deck->profile?->nickname }}</option>@endforeach
                    </optgroup>
                @endif
            </select>
        </label>
        @if($side === 'left')<span class="deckcmp-arrow" aria-hidden="true"><x-icon name="arrow-right" /></span>@endif
    @endforeach
    <button class="ui-btn ui-btn-primary">Comparar</button>
</form>

@if($rows === null)
    <div class="ui-empty">
        <span class="ui-empty-icon"><x-icon name="repeat" :size="18" /></span>
        <div><p class="ui-empty-title">Escolha dois decks</p><p class="ui-empty-text">A comparação mostra o que tirar e o que colocar para ir do primeiro deck até o segundo.</p></div>
    </div>
@else
    @php($rowSet = collect($rows))
    @php($removals = $rowSet->where('delta', '<', 0)->sortBy('delta')->values())
    @php($additions = $rowSet->where('delta', '>', 0)->sortByDesc('delta')->values())
    @php($unchanged = $rowSet->where('delta', 0)->values())
    {{-- Resultado: o caminho de um deck até o outro. O que sai, o que entra e, recolhido, o que é igual. --}}
    <section class="deckcmp-result" aria-label="Resultado da comparação">
        <div class="deckcmp-head">
            <h2><a href="{{ route('decks.show', $left) }}">{{ $left->name }}</a> <x-icon name="arrow-right" /> <a href="{{ route('decks.show', $right) }}">{{ $right->name }}</a></h2>
            <a class="ui-btn ui-btn-ghost ui-btn-sm" href="{{ route('decks.compare', ['left' => $right->id, 'right' => $left->id]) }}"><x-icon name="repeat" />Inverter</a>
        </div>
        @if($rowSet->isEmpty())
            <div class="ui-empty"><span class="ui-empty-icon"><x-icon name="layers" :size="18" /></span><div><p class="ui-empty-title">Ambos os decks estão vazios.</p></div></div>
        @else
            <p class="deckcmp-summary">
                <span class="deckcmp-chip is-out"><b>{{ abs($removals->sum('delta')) }}</b> para tirar</span>
                <span class="deckcmp-chip is-in"><b>{{ $additions->sum('delta') }}</b> para colocar</span>
                <span class="deckcmp-chip"><b>{{ $unchanged->sum('left') }}</b> iguais</span>
            </p>
            @if($removals->isEmpty() && $additions->isEmpty())
                <p class="ui-hint">As duas listas têm as mesmas cartas nas mesmas quantidades.</p>
            @else
                <div class="deckcmp-cols">
                    @foreach([['Tirar', 'is-out', $removals, 'Nada para tirar.'], ['Colocar', 'is-in', $additions, 'Nada para colocar.']] as [$colTitle, $colClass, $colRows, $colEmpty])
                        <div class="card deckcmp-col">
                            <h3>{{ $colTitle }} <span>{{ abs($colRows->sum('delta')) }}</span></h3>
                            @if($colRows->isEmpty())
                                <p class="deckcmp-none">{{ $colEmpty }}</p>
                            @else
                                <ul class="deckcmp-list">
                                    @foreach($colRows as $row)
                                        <li>
                                            <span class="deckcmp-delta {{ $colClass }}">{{ $row['delta'] > 0 ? '+' : '' }}{{ $row['delta'] }}</span>
                                            <span class="deckcmp-name">{{ $row['name'] }}@if(isset($boardNames[$row['board']])) <small>{{ $boardNames[$row['board']] }}</small>@endif</span>
                                            <span class="deckcmp-qty" title="No primeiro deck: {{ $row['left'] }} · No segundo: {{ $row['right'] }}">{{ $row['left'] }} → {{ $row['right'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
            @if($unchanged->isNotEmpty())
                <details class="card deckcmp-same">
                    <summary>Cartas iguais nos dois <span>{{ $unchanged->sum('left') }}</span></summary>
                    <ul class="deckcmp-list">
                        @foreach($unchanged as $row)
                            <li>
                                <span class="deckcmp-delta">{{ $row['left'] }}</span>
                                <span class="deckcmp-name">{{ $row['name'] }}@if(isset($boardNames[$row['board']])) <small>{{ $boardNames[$row['board']] }}</small>@endif @if($row['printing_changed'])<small>impressões diferentes</small>@endif</span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @endif
    </section>
@endif
@endsection
