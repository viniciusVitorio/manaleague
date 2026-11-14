{{-- Placar com os jogadores, no formato do resumo da partida: foto e nome nas pontas e, embaixo de cada um, o mini
     contador (− número +, score-step.js) para marcar as vitórias ou, só para leitura, o placar enviado.
     Usado nos cartões de partida em andamento (torneio e desafio).
     $sides = 2 × ['name' => string, 'profile' => PlayerProfile|null, 'me' => bool, 'field' => nome do campo|null,
                   'value' => int|null, 'winner' => bool]; $max = vitórias possíveis na série (com campo). --}}
<div class="mm-board">
    @foreach($sides as $i => $side)
        @if($i === 1)<span class="mm-board-vs" aria-hidden="true">–</span>@endif
        <div @class(['mm-board-side', 'is-winner' => $side['winner'] ?? false])>
            @if($side['profile'])
                <x-avatar :of="$side['profile']" class="mm-board-avatar" aria-hidden="true" />
            @else
                <span class="mm-board-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($side['name'], 0, 1)) }}</span>
            @endif
            <span class="mm-board-name" title="{{ $side['name'] }}">{{ $side['me'] ? 'Você' : $side['name'] }}</span>
            @if(! empty($side['field']))
                <span class="mm-step" data-score-step>
                    <button type="button" class="mm-step-btn" data-step="-1" aria-label="Uma vitória a menos para {{ $side['name'] }}">−</button>
                    <input type="number" inputmode="numeric" name="{{ $side['field'] }}" min="0" max="{{ $max }}" required placeholder="0" aria-label="Vitórias de {{ $side['name'] }}">
                    <button type="button" class="mm-step-btn" data-step="1" aria-label="Uma vitória a mais para {{ $side['name'] }}">+</button>
                </span>
            @else
                <b class="mm-board-score">{{ $side['value'] }}</b>
            @endif
        </div>
    @endforeach
</div>
