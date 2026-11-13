{{-- Gráfico de vida da partida (LifeLogService::chartFor): um quadro por game, uma linha por jogador ligando os lances,
     com a vida inicial no meio do eixo e o avatar no fim da linha. Texto, marcadores e avatares ficam em HTML (posicionados
     em %) e o SVG só desenha; life-chart.js põe a leitura com o dedo/mouse. --}}
<div class="lchart">
    <ul class="lchart-legend" aria-label="Jogadores">
        @foreach($chart['names'] as $seat => $name)
            <li>@include('life.partials.chart-avatar', ['profile' => $chart['people'][$seat] ?? null, 'name' => $name, 'seat' => $seat]){{ $name }}</li>
        @endforeach
    </ul>

    @foreach($chart['games'] as $index => $game)
        <figure class="lchart-game" data-life-chart="{{ json_encode(['names' => $chart['names'], 'duration' => $game['duration'], 'steps' => $game['steps']], JSON_THROW_ON_ERROR) }}">
            <figcaption>
                <strong>Game {{ $index + 1 }}</strong>
                <span>@if($game['winner'] !== null)vitória de {{ $chart['names'][$game['winner']] }}@else sem vencedor @endif · {{ \App\Support\LifeChart::minutes($game['duration']) }}</span>
            </figcaption>
            <div class="lchart-frame">
                <div class="lchart-y" aria-hidden="true">
                    @foreach($game['ticks'] as $tick)
                        <span @class(['is-start' => $tick['start']]) style="top:{{ $tick['y'] }}%">{{ $tick['value'] }}</span>
                    @endforeach
                </div>
                <div class="lchart-plot" tabindex="0" role="img" aria-label="Vida de cada jogador no game {{ $index + 1 }}: {{ collect($game['series'])->map(fn ($line) => $line['name'].' terminou com '.$line['end']['value'])->implode(', ') }}.">
                    <svg viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                        @foreach($game['ticks'] as $tick)
                            <line x1="0" x2="1000" y1="{{ $tick['y'] * 10 }}" y2="{{ $tick['y'] * 10 }}" @class(['lchart-grid', 'is-zero' => $tick['value'] === 0, 'is-start' => $tick['start']]) />
                        @endforeach
                        @foreach($game['series'] as $line)
                            <path d="{{ $line['path'] }}" class="lchart-line s{{ $line['seat'] + 1 }}" />
                        @endforeach
                    </svg>
                    @foreach($game['series'] as $line)
                        @foreach($line['marks'] as $mark)
                            <i class="lchart-mark s{{ $line['seat'] + 1 }}" style="left:{{ $mark['x'] }}%;top:{{ $mark['y'] }}%" aria-hidden="true"></i>
                        @endforeach
                    @endforeach
                    @foreach($game['series'] as $line)
                        <span @class(['lchart-end', 'is-out' => $line['out']]) style="left:{{ $line['end']['x'] }}%;top:{{ $line['labelY'] }}%" aria-hidden="true">@include('life.partials.chart-avatar', ['profile' => $chart['people'][$line['seat']] ?? null, 'name' => $line['name'], 'seat' => $line['seat']])</span>
                    @endforeach
                    <div class="lchart-cross" hidden></div>
                    <div class="lchart-tip" role="status" hidden></div>
                </div>
                <ul class="lchart-ends" aria-hidden="true">
                    @foreach($game['series'] as $line)
                        @unless($line['out'])
                            <li style="top:{{ $line['labelY'] }}%"><b>{{ $line['end']['value'] }}</b></li>
                        @endunless
                    @endforeach
                </ul>
            </div>
            <div class="lchart-x" aria-hidden="true">
                @foreach($game['xTicks'] as $tick)
                    <span style="left:{{ $tick['x'] }}%">{{ $tick['label'] }}</span>
                @endforeach
            </div>
        </figure>
    @endforeach

    <details class="lchart-table">
        <summary>Ver lance a lance <x-icon name="chevron-down" size="14" /></summary>
        @foreach($chart['games'] as $index => $game)
            <table>
                <caption>Game {{ $index + 1 }}</caption>
                <thead><tr><th scope="col">Momento</th><th scope="col">Jogador</th><th scope="col">Vida</th><th scope="col">Mudança</th></tr></thead>
                <tbody>
                    @forelse($game['rows'] as $row)
                        <tr>
                            <td>{{ $row['time'] }}</td>
                            <td><i class="lchart-key s{{ $row['seat'] + 1 }}" aria-hidden="true"></i>{{ $row['name'] }}</td>
                            <td>{{ $row['life'] }}@if($row['poison']) <small>☠ {{ $row['poison'] }}</small>@endif</td>
                            <td @class(['is-up' => $row['delta'] > 0, 'is-down' => $row['delta'] < 0])>{{ $row['delta'] > 0 ? '+'.$row['delta'] : ($row['delta'] < 0 ? '−'.abs($row['delta']) : '±0') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Nenhuma mudança de vida neste game.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endforeach
    </details>
</div>
